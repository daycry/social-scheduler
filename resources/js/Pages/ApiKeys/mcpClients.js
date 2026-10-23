// Plantillas de instalación del servidor MCP por cliente y prueba de conexión desde el navegador.
// La key solo vive en memoria (prop `newKey` tras crearla): nunca se guarda ni se reenvía al servidor de la app.

export const SERVER_NAME = 'social-scheduler';
export const KEY_ENV = 'SCHEDULER_MCP_KEY';
export const KEY_PLACEHOLDER = '<TU_API_KEY>';

const json = (value) => JSON.stringify(value, null, 2);

// Comando para guardar la key en una variable de entorno (Codex y OpenCode la leen de ahí, no del fichero).
const envSteps = (key) => [
    { label: 'Windows (PowerShell); abre una terminal nueva después', code: `setx ${KEY_ENV} "${key}"` },
    { label: 'macOS / Linux (añádelo a tu ~/.zshrc o ~/.bashrc)', code: `export ${KEY_ENV}="${key}"` },
];

/**
 * @param {string} endpoint URL absoluta de /mcp
 * @param {string|null} newKey key recién creada, o null para mostrar el marcador
 * @returns {Array<{id: string, label: string, intro: string, steps: Array<{label: string, code: string}>, notes: string[]}>}
 */
export function mcpClients(endpoint, newKey) {
    const key = newKey || KEY_PLACEHOLDER;

    return [
        {
            id: 'claude-code',
            label: 'Claude Code',
            intro: 'Ejecuta en una terminal. Con --scope user queda disponible en todos tus proyectos.',
            steps: [
                {
                    label: 'Terminal',
                    code: `claude mcp add --transport http --scope user ${SERVER_NAME} ${endpoint} --header "Authorization: Bearer ${key}"`,
                },
            ],
            notes: ['Compruébalo con «claude mcp list» o escribiendo /mcp dentro de Claude Code.'],
        },
        {
            id: 'claude-desktop',
            label: 'Claude Desktop',
            intro:
                'Claude Desktop no admite cabeceras en conectores remotos, así que se usa el puente mcp-remote (necesita Node.js). '
                + 'Añade este bloque a claude_desktop_config.json (Ajustes → Desarrollador → Editar configuración) y reinicia Claude Desktop por completo.',
            steps: [
                {
                    label: 'Windows: %APPDATA%\\Claude\\claude_desktop_config.json · macOS: ~/Library/Application Support/Claude/claude_desktop_config.json',
                    code: json({
                        mcpServers: {
                            [SERVER_NAME]: {
                                command: 'npx',
                                // Sin espacios en los args: Claude Desktop en Windows no los escapa al lanzar npx.
                                args: ['-y', 'mcp-remote', endpoint, '--header', 'Authorization:${AUTH_HEADER}', '--transport', 'http-only'],
                                env: { AUTH_HEADER: `Bearer ${key}` },
                            },
                        },
                    }),
                },
            ],
            notes: ['Si el fichero ya tiene otros servidores, añade solo la entrada dentro de «mcpServers».'],
        },
        {
            id: 'codex',
            label: 'Codex',
            intro: 'Guarda la key en una variable de entorno y añade el servidor a ~/.codex/config.toml (en Windows, %USERPROFILE%\\.codex\\config.toml).',
            steps: [
                ...envSteps(key),
                {
                    label: '~/.codex/config.toml',
                    code: `[mcp_servers.${SERVER_NAME}]\nurl = "${endpoint}"\nbearer_token_env_var = "${KEY_ENV}"\n`,
                },
            ],
            notes: ['Compruébalo con «codex mcp list» o escribiendo /mcp dentro de Codex.'],
        },
        {
            id: 'opencode',
            label: 'OpenCode',
            intro:
                'Guarda la key en una variable de entorno y añade el servidor a ~/.config/opencode/opencode.json '
                + '(o a opencode.json en la raíz de un proyecto).',
            steps: [
                ...envSteps(key),
                {
                    label: 'opencode.json',
                    code: json({
                        $schema: 'https://opencode.ai/config.json',
                        mcp: {
                            [SERVER_NAME]: {
                                type: 'remote',
                                url: endpoint,
                                enabled: true,
                                oauth: false,
                                headers: { Authorization: `Bearer {env:${KEY_ENV}}` },
                            },
                        },
                    }),
                },
            ],
            notes: ['Si el fichero ya existe, añade solo la entrada dentro de «mcp».'],
        },
    ];
}

const MESSAGES = {
    401: 'La API key no es válida, está revocada o ha caducado.',
    403: 'El servidor rechazó el origen de la petición.',
    429: 'Demasiadas peticiones: espera un minuto y vuelve a probar.',
};
const NOT_MCP = 'El servidor no devolvió una respuesta MCP (¿hay un proxy o una página de aviso delante?).';
const MAX_PAGES = 20;

class ProbeError extends Error {}

// Cuerpo JSON-RPC de la respuesta a la petición `id`: JSON directo o SSE (varios eventos, cada uno con una o
// varias líneas `data:`). Devuelve null si no es JSON-RPC.
export function parseRpcBody(text, contentType, id) {
    const parse = (raw) => {
        try {
            const value = JSON.parse(raw);
            return value && typeof value === 'object' ? value : null;
        } catch {
            return null;
        }
    };
    if (!(contentType || '').includes('text/event-stream')) return parse(text);

    const events = text.split(/\r?\n\r?\n/).map((block) =>
        block.split(/\r?\n/).filter((l) => l.startsWith('data:')).map((l) => l.slice(5).replace(/^ /, '')).join('\n'));

    return events.map(parse).find((msg) => msg && msg.id === id) ?? null;
}

/**
 * initialize + tools/list (todas las páginas) contra el endpoint con la key indicada, igual que haría un cliente MCP.
 * Los permisos de la key se comprueban al USAR cada herramienta, no al listarlas: el total es el del servidor.
 * @returns {Promise<{ok: true, tools: number} | {ok: false, message: string}>}
 */
export async function testConnection(endpoint, key, fetchImpl = fetch) {
    let id = 0;
    const post = async (body) => {
        try {
            return await fetchImpl(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json, text/event-stream',
                    Authorization: `Bearer ${key}`,
                    // Túneles ngrok gratuitos: sin esto devuelven su página HTML de aviso en vez de la respuesta.
                    'ngrok-skip-browser-warning': '1',
                },
                credentials: 'omit', // solo la key autentica: nunca la cookie de sesión
                body: JSON.stringify(body),
            });
        } catch {
            throw new ProbeError('No se pudo contactar con el servidor.');
        }
    };
    const call = async (method, params = {}) => {
        const requestId = ++id;
        const response = await post({ jsonrpc: '2.0', id: requestId, method, params });
        const body = parseRpcBody(await response.text(), response.headers.get('content-type'), requestId);
        if (!response.ok) {
            throw new ProbeError(MESSAGES[response.status] || body?.error?.message || `El servidor respondió ${response.status}.`);
        }
        if (!body) throw new ProbeError(NOT_MCP);
        if (body.error) throw new ProbeError(body.error.message || 'Error del servidor MCP.');
        if (!body.result || typeof body.result !== 'object') throw new ProbeError(NOT_MCP);

        return body.result;
    };

    try {
        await call('initialize', {
            protocolVersion: '2025-06-18',
            capabilities: {},
            clientInfo: { name: 'social-scheduler-web', version: '1' },
        });
        // Notificación sin respuesta: un servidor que la ignore no invalida la prueba.
        await post({ jsonrpc: '2.0', method: 'notifications/initialized' }).catch(() => null);

        let tools = 0;
        let cursor;
        for (let page = 0; page < MAX_PAGES; page++) {
            const result = await call('tools/list', cursor ? { cursor } : {});
            if (!Array.isArray(result.tools)) throw new ProbeError(NOT_MCP);
            tools += result.tools.length;
            cursor = result.nextCursor;
            if (!cursor) break;
        }

        return { ok: true, tools };
    } catch (e) {
        return { ok: false, message: e instanceof ProbeError ? e.message : 'Error inesperado al probar la conexión.' };
    }
}
