export type User = {
    id: string;
    name?: string;
    email: string | null;
    login_name: string;
    login_type_id: string | null;
    school_id: string | null;
    eula_signed: string | null;
    active: 'Y' | 'N' | null;
    expiration_date: string | null;
    lname: string | null;
    fname: string | null;
    mname: string | null;
    sex: 'M' | 'F' | null;
    avatar?: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};