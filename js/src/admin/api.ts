import app from 'flarum/admin/app';
import type Model from 'flarum/common/Model';

/**
 * Create or change a queue or rule. Not Model#save: that drops a
 * relationship set to null ("no queue", "no one").
 */
export function save(
  type: string,
  id: string | null,
  attributes: Record<string, unknown>,
  relationships: Record<string, Model | Model[] | null> = {}
) {
  const rels: Record<string, unknown> = {};

  for (const [key, value] of Object.entries(relationships)) {
    const ident = (model: Model) => ({ type: model.data.type, id: model.id() });
    rels[key] = { data: Array.isArray(value) ? value.map(ident) : value ? ident(value) : null };
  }

  const data: Record<string, unknown> = { type, attributes, relationships: rels };
  if (id) data.id = id;

  return app
    .request<any>({ method: id ? 'PATCH' : 'POST', url: `${app.forum.attribute('apiUrl')}/${type}${id ? '/' + id : ''}`, body: { data } })
    .then((payload) => app.store.pushPayload(payload));
}

export function saveOrder(type: 'queues' | 'rules', ids: string[]) {
  return app.request({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/docket-order?type=${type}`, body: { order: ids } });
}
