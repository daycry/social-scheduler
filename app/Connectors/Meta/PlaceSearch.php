<?php

namespace App\Connectors\Meta;

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Models\SocialAccount;

/**
 * Busqueda de lugares de Meta para `location_id` (Instagram) y `place` (Facebook): `GET /pages/search?q=…&fields=id,name,location`, el
 * «Pages Search» que la documentacion de publicacion de Instagram indica para obtener el id de la Pagina de un lugar. Meta restringe esta busqueda
 * (funcion «Page Public Metadata Access» de la app, aprobada en la revision de Meta): sin ella devuelve un error de permisos. Ese caso NO es un
 * fallo de la app: `PlaceSearchUnavailable` lleva el motivo y la UI deja pegar el id a mano. Nunca se cachea ni se guarda el resultado.
 */
final class PlaceSearch
{
    public const LIMIT = 10;

    public function __construct(private readonly GraphClient $graph = new GraphClient) {}

    /**
     * @return list<array{id: string, name: string, detail: ?string}>
     *
     * @throws PlaceSearchUnavailable
     */
    public function search(SocialAccount $account, string $query): array
    {
        try {
            $json = $this->graph->get('pages/search', ['q' => $query, 'fields' => 'id,name,location{street,city,country}', 'limit' => self::LIMIT], (string) $account->access_token, read: true);
        } catch (PermanentPublishException $e) {
            throw new PlaceSearchUnavailable($e->errorCode === 'permisos'
                ? 'Meta no permite buscar lugares con esta aplicación (hace falta la función «Page Public Metadata Access», aprobada en la revisión de Meta). Pega el id del lugar a mano.'
                : 'Meta rechazó la búsqueda de lugares ('.$e->getMessage().'). Pega el id del lugar a mano.', $e);
        } catch (ReconnectRequiredException $e) {
            throw new PlaceSearchUnavailable($e->getMessage(), $e);
        } catch (TransientPublishException $e) {
            throw new PlaceSearchUnavailable('Meta no respondió a la búsqueda de lugares; inténtalo de nuevo en un momento o pega el id a mano.', $e);
        } catch (ConnectorException $e) {
            throw new PlaceSearchUnavailable($e->getMessage(), $e);
        }

        $places = [];
        foreach ((array) ($json['data'] ?? []) as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || preg_match('/^\d{1,20}$/D', $row['id']) !== 1 || ! is_string($row['name'] ?? null)) {
                continue;
            }
            $loc = is_array($row['location'] ?? null) ? $row['location'] : [];
            $detail = implode(', ', array_filter(array_map(fn ($k) => is_string($loc[$k] ?? null) ? trim($loc[$k]) : '', ['street', 'city', 'country'])));
            $places[] = ['id' => $row['id'], 'name' => mb_substr($row['name'], 0, 120), 'detail' => $detail !== '' ? mb_substr($detail, 0, 160) : null];
        }

        return array_slice($places, 0, self::LIMIT);
    }
}
