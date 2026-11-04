const encode = value => JSON.stringify(value);
const decode = value => JSON.parse(value);

/** Full immutable snapshots: grouping and page operations undo as one transaction. */
export function createHistory(initial, { limit = 80, maxBytes = 16 * 1024 * 1024 } = {}) {
    const capacity = Math.max(2, Math.floor(limit));
    let states = [encode(initial)];
    let index = 0;
    const bytes = value => new TextEncoder().encode(value).byteLength;
    return {
        get current() { return decode(states[index]); },
        get canUndo() { return index > 0; },
        get canRedo() { return index < states.length - 1; },
        get length() { return states.length; },
        commit(value) {
            const next = encode(value);
            if (next === states[index]) return false;
            states = [...states.slice(0, index + 1), next];
            while (states.length > capacity || (states.length > 2 && states.reduce((sum, state) => sum + bytes(state), 0) > maxBytes)) states.shift();
            index = states.length - 1;
            return true;
        },
        undo() { return index > 0 ? decode(states[--index]) : null; },
        redo() { return index < states.length - 1 ? decode(states[++index]) : null; },
        reset(value) { states = [encode(value)]; index = 0; return decode(states[0]); },
    };
}
