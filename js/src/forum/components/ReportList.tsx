import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Placeholder from 'flarum/common/components/Placeholder';
import type Mithril from 'mithril';
import type { Report } from '../../common/models';
import ReportListItem from './ReportListItem';
import { t } from '../t';

export interface ReportListAttrs extends ComponentAttrs {
  filter: Record<string, string>;
  /** Leave this report out (the one already on screen). */
  except?: string;
  limit?: number;
  /** No "load more": a short list with a link elsewhere. */
  brief?: boolean;
  empty?: Mithril.Children;
}

/**
 * A page of reports for the given filter, with "load more".
 */
export default class ReportList extends Component<ReportListAttrs> {
  reports: Report[] = [];
  loading = true;
  more = false;
  fetched = 0;
  key = '';

  oninit(vnode: Mithril.Vnode<ReportListAttrs, this>) {
    super.oninit(vnode);
    this.refresh();
  }

  onbeforeupdate(vnode: Mithril.VnodeDOM<ReportListAttrs, this>) {
    super.onbeforeupdate(vnode);

    if (JSON.stringify(vnode.attrs.filter) !== this.key) this.refresh();
  }

  refresh() {
    this.key = JSON.stringify(this.attrs.filter);
    this.reports = [];
    this.fetched = 0;
    this.load(0);
  }

  load(offset: number) {
    this.loading = true;
    const key = this.key;

    app.store
      .find<Report[]>('docket-reports', {
        filter: this.attrs.filter,
        include: 'post,post.user,user,assignee,queue,entries',
        page: { offset, limit: this.attrs.limit || 20 },
      })
      .then((results) => {
        // A newer filter replaced this one while it loaded.
        if (key !== this.key) return;

        this.fetched += results.length;
        this.reports.push(...results.filter((report) => report.id() !== this.attrs.except));
        this.more = !this.attrs.brief && !!(results as any).payload?.links?.next;
      })
      .catch(() => {})
      .finally(() => {
        if (key === this.key) this.loading = false;
        m.redraw();
      });
  }

  view() {
    if (!this.loading && !this.reports.length) {
      return this.attrs.empty === undefined ? <Placeholder text={t('list.empty')} /> : this.attrs.empty;
    }

    return (
      <div className="DocketList">
        <ul className="DocketList-items">
          {this.reports.map((report) => (
            <li key={report.id()}>
              <ReportListItem report={report} />
            </li>
          ))}
        </ul>
        {this.loading ? <LoadingIndicator /> : null}
        {this.more && !this.loading ? (
          <div className="DocketList-more">
            <Button className="Button" onclick={() => this.load(this.fetched)}>
              {t('list.load_more')}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }
}
