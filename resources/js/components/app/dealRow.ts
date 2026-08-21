/**
 * The DealRow column spec — Design System §7.3.
 *
 * The header and the body read this same array, because misaligning header
 * and body by even 2px is visible. Cells are named `primary`, `meta1`,
 * `meta2`, `state`, `date`, `owner` on purpose: the dashboard hides `meta1`
 * and narrows `meta2`, while the deals index shows all seven. Do not rename
 * them after their S13 content.
 */

export type DealRowCellKey =
    'select' | 'primary' | 'meta1' | 'meta2' | 'state' | 'date' | 'owner';

export interface DealRowColumn {
    key: DealRowCellKey;
    label: string;
    /** Fixed pixel width, or null for the one flexible cell. */
    width: number | null;
    align?: 'left' | 'right' | 'center';
    sortable?: boolean;
}

const DEFAULTS: DealRowColumn[] = [
    { key: 'select', label: '', width: 30, align: 'center' },
    { key: 'primary', label: 'Deal', width: null, sortable: true },
    { key: 'meta1', label: 'Client', width: 170 },
    { key: 'meta2', label: 'Stage', width: 150 },
    { key: 'state', label: 'Status', width: 140 },
    { key: 'date', label: 'Next date', width: 115, sortable: true },
    { key: 'owner', label: 'Owner', width: 40, align: 'center' },
];

export interface DealRowColumnOptions {
    /** The select cell is hidden unless the screen supports bulk select. */
    selectable?: boolean;
    hide?: DealRowCellKey[];
    widths?: Partial<Record<DealRowCellKey, number>>;
    labels?: Partial<Record<DealRowCellKey, string>>;
}

export function dealRowColumns(
    options: DealRowColumnOptions = {},
): DealRowColumn[] {
    const hidden = new Set<DealRowCellKey>(options.hide ?? []);

    if (!options.selectable) {
        hidden.add('select');
    }

    return DEFAULTS.filter((column) => !hidden.has(column.key)).map(
        (column) => ({
            ...column,
            width: options.widths?.[column.key] ?? column.width,
            label: options.labels?.[column.key] ?? column.label,
        }),
    );
}
