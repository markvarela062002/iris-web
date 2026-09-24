
    export type CalendarEventSeverity =
        | 'primary'
        | 'secondary'
        | 'success'
        | 'info'
        | 'warn'
        | 'danger'
        | 'help'
        | 'contrast';

    export type CalendarEvent = {
        id: string;
        type: string;
        date: string;
        count: number;
        label: string;
        severity?: CalendarEventSeverity;
        icon?: string;
    };

    export type CalendarDay = {
        date: string;
        day: number;
        currentMonth: boolean;
        today: boolean;
        events: CalendarEvent[];
    };