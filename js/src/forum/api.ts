import app from 'flarum/forum/app';
import type User from 'flarum/common/models/User';
import type { Queue, Report } from '../common/models';

/**
 * Change a report. Not Model#save: that drops a relationship set to null,
 * and "no one" and "the general queue" are both null.
 */
export function updateReport(
  report: Report,
  attributes: Record<string, unknown>,
  relationships: { assignee?: User | null; queue?: Queue | null } = {}
): Promise<Report> {
  const rels: Record<string, unknown> = {};

  for (const [key, model] of Object.entries(relationships)) {
    rels[key] = { data: model ? { type: model.data.type, id: model.id() } : null };
  }

  return app
    .request<any>({
      method: 'PATCH',
      url: `${app.forum.attribute('apiUrl')}/docket-reports/${report.id()}`,
      body: { data: { type: 'docket-reports', id: report.id(), attributes, relationships: rels } },
    })
    .then((payload) => app.store.pushPayload(payload) as unknown as Report);
}
