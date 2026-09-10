export type DataTableRow = Record<string, unknown>;

export type DataTableColumn = {
    field: string;
    header: string;
    sortable?: boolean;
    searchable?: boolean;
    frozen?: boolean;
    alignFrozen?: 'left' | 'right';
    class?: string;
    headerClass?: string;
    bodyClass?: string;
    format?: (
        value: unknown,
        row: DataTableRow,
    ) => string | number;
};

export type DataTableAction = {
    key: string;
    label: string;
    icon: string;
    severity?:
        | 'primary'
        | 'secondary'
        | 'success'
        | 'info'
        | 'warn'
        | 'danger'
        | 'help'
        | 'contrast';
    visible?: (row: DataTableRow) => boolean;
    disabled?: (row: DataTableRow) => boolean;
};