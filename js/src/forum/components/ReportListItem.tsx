import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Avatar from 'flarum/common/components/Avatar';
import Link from 'flarum/common/components/Link';
import username from 'flarum/common/helpers/username';
import humanTime from 'flarum/common/helpers/humanTime';
import { truncate } from 'flarum/common/utils/string';
import type { Report } from '../../common/models';
import { statusBadge, reasonLabel } from '../labels';
import { t } from '../t';
import extractText from 'flarum/common/utils/extractText';

export interface ReportListItemAttrs extends ComponentAttrs {
  report: Report;
}

export default class ReportListItem extends Component<ReportListItemAttrs> {
  view() {
    const report = this.attrs.report;
    const post = report.post();
    const place = report.place();
    const queue = report.queue();
    const assignee = report.assignee();

    // One of each reason, in the order they came.
    const reasons = Array.from(new Set((report.entries() || []).filter(Boolean).map((entry) => extractText(reasonLabel(entry!)))));

    return (
      <Link href={app.route('docket.report', { id: report.id() })} className="DocketItem">
        <Avatar user={report.user() || null} className="DocketItem-avatar" />
        <div className="DocketItem-main">
          <div className="DocketItem-title">
            <span className="DocketItem-id">#{report.id()}</span> {place ? place.title : t('list.post_gone')}
          </div>
          {post ? <div className="DocketItem-excerpt">{truncate(post.contentPlain() || '', 160)}</div> : null}
          <div className="DocketItem-meta">
            <span>{t('list.by', { name: username(report.user() || null) })}</span>
            {reasons.length ? <span>{reasons.join(', ')}</span> : null}
            <span>{t('list.report_count', { count: report.entryCount() })}</span>
            <span>{humanTime(report.updatedAt()!)}</span>
          </div>
        </div>
        <div className="DocketItem-side">
          {statusBadge(report.status())}
          {queue ? (
            <span className="DocketQueueChip" style={queue.color() ? { '--docket-queue': queue.color() } : undefined}>
              {queue.name()}
            </span>
          ) : null}
          {assignee ? (
            <span className="DocketItem-assignee" title={t('list.held_by', { name: assignee.displayName() })}>
              <Avatar user={assignee} />
            </span>
          ) : null}
        </div>
      </Link>
    );
  }
}
