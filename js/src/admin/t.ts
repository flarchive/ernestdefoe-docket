import app from 'flarum/admin/app';

/** This extension's admin strings, by the part of the key after `admin.`. */
export const t = (k: string, p?: any): any => app.translator.trans('ernestdefoe-docket.admin.' + k, p);
