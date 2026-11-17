// Keep server decimal strings exact, including amounts smaller than one cent.
export function money(value) {
    if (value === null || value === undefined) return '—';
    const match = String(value).match(/^(-?)(\d+)(?:\.(\d{1,18}))?$/);
    if (!match) return '—';
    const [, sign, whole, decimals = ''] = match;
    return sign + '$' + whole + '.' + decimals.replace(/0+$/, '').padEnd(4, '0');
}

export function compareMoney(left, right) {
    const units = (value) => {
        const match = String(value).match(/^(-?)(\d+)(?:\.(\d{1,18}))?$/);
        if (!match) return null;
        const [, sign, whole, decimals = ''] = match;
        return BigInt(sign + whole + decimals.padEnd(18, '0'));
    };
    const a = units(left);
    const b = units(right);
    if (a === null || b === null) return null;
    return a < b ? -1 : a > b ? 1 : 0;
}
