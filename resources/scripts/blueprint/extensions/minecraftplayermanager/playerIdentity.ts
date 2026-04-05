export function normalizePlayerUuid(value?: string | null): string {
    return (value ?? '').replace(/-/g, '').toLowerCase();
}

export function samePlayerUuid(a?: string | null, b?: string | null): boolean {
    const na = normalizePlayerUuid(a);
    const nb = normalizePlayerUuid(b);
    if (!na || !nb) {
        return false;
    }
    return na === nb;
}

export function isSamePlayerIdentity(
    left: { uuid?: string | null; name?: string | null },
    right: { uuid?: string | null; name?: string | null }
): boolean {
    const leftUuid = normalizePlayerUuid(left.uuid);
    const rightUuid = normalizePlayerUuid(right.uuid);

    if (leftUuid && rightUuid) {
        if (leftUuid === rightUuid) {
            return true;
        }
        const leftName = (left.name ?? '').trim().toLowerCase();
        const rightName = (right.name ?? '').trim().toLowerCase();
        if (leftName !== '' && leftName === rightName) {
            return true;
        }

        return false;
    }

    const leftName = (left.name ?? '').trim().toLowerCase();
    const rightName = (right.name ?? '').trim().toLowerCase();

    return leftName !== '' && leftName === rightName;
}
