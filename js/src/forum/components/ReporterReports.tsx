import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';
import type { Entry, ReporterRecord } from '../../common/models';
import { placeUrl, reasonLabel, statusBadge } from '../labels';
import { recordPanel } from '../record';
import { t } from '../t';

export interface ReporterReportsAttrs extends ComponentAttrs {
  user: User;
}

/**
 * The reports a member made, for staff: their record by time frame, then
 * each report, newest first, with how it really went.
 */
export default class ReporterReports extends Component<ReporterReportsAttrs> {
  entries: Entry[] = [];
  record: ReporterRecord | null = null;
  loading = true;
  more = false;

  oninit(vnode: Mithril.Vnode<ReporterReportsAttrs, this>) {
    super.oninit(vnode);
    this.load(0);
  }

  load(offset: number) {
    this.loading = true;

    app.store
      .find<Entry[]>('docket-entries', { filter: { reporter: this.attrs.user.id()! }, page: { offset, limit: 20 } })
      .then((entries) => {
        const payload = (entries as any).payload;
        this.entries.push(...entries);
        this.record = payload?.meta?.reporterStats || this.record;
        this.more = !!payload?.links?.next;
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    return (
      <div className="DocketReporter">
        {this.record ? recordPanel(this.record) : null}
        {!this.loading && !this.entries.length ? <p className="DocketHistory-empty">{t('record.empty')}</p> : null}
        <ul className="DocketMine">
          {this.entries.map((entry) => {
            const place = entry.place();

            return (
              <li className="DocketCard DocketMine-item" key={entry.id()}>
                <div className="DocketMine-head">
                  {statusBadge(entry.verdict() || entry.reportStatus())}
                  <span className="DocketMine-when">
                    <Link href={app.route('docket.report', { id: entry.reportId() })}>{t('report.title', { id: entry.reportId() })}</Link> ·{' '}
                    {humanTime(entry.createdAt()!)}
                  </span>
                </div>
                <div className="DocketMine-what">
                  {place ? (
                    <Link href={placeUrl(place)}>{place.title}</Link>
                  ) : (
                    <span className="DocketMine-gone">{t('record.post_unavailable')}</span>
                  )}
                </div>
                <div className="DocketMine-reason">
                  {t('record.reason')} {reasonLabel(entry)}
                  {entry.reasonDetail() ? <span className="DocketMine-detail"> — {entry.reasonDetail()}</span> : null}
                </div>
              </li>
            );
          })}
        </ul>
        {this.loading ? <LoadingIndicator /> : null}
        {this.more && !this.loading ? (
          <div className="DocketList-more">
            <Button className="Button" onclick={() => this.load(this.entries.length)}>
              {t('list.load_more')}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }
}
