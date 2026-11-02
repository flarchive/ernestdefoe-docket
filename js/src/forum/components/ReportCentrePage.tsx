import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import LinkButton from 'flarum/common/components/LinkButton';
import SelectDropdown from 'flarum/common/components/SelectDropdown';
import listItems from 'flarum/common/helpers/listItems';
import ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';
import type { Queue, Report } from '../../common/models';
import ReportList from './ReportList';
import { docketNav, docketPage } from './DocketHero';
import { t } from '../t';

/** Each view: the list filter it applies. */
const VIEWS: Record<string, { icon: string; filter: Record<string, string> }> = {
  active: { icon: 'fas fa-inbox', filter: { status: 'open,in_progress' } },
  unassigned: { icon: 'far fa-circle', filter: { status: 'open' } },
  mine: { icon: 'fas fa-user-check', filter: { status: 'open,in_progress', assignee: 'me' } },
  resolved: { icon: 'fas fa-circle-check', filter: { status: 'resolved' } },
  rejected: { icon: 'fas fa-circle-xmark', filter: { status: 'rejected' } },
  all: { icon: 'fas fa-list', filter: {} },
};

/**
 * Where the lists scroll sideways (a phone), bring the open one into sight
 * when it opens, and leave the bar alone after that.
 */
function showActive({ dom }: Mithril.VnodeDOM) {
  const active = dom.querySelector<HTMLElement>('[active="true"]');
  const href = active?.getAttribute('href') || '';
  if (!active || (dom as HTMLElement).dataset.shown === href) return;
  (dom as HTMLElement).dataset.shown = href;
  if (dom.scrollWidth <= dom.clientWidth) return;

  const a = active.getBoundingClientRect();
  const d = dom.getBoundingClientRect();
  if (a.left < d.left || a.right > d.right) dom.scrollLeft += a.left - d.left - (d.width - a.width) / 2;
}

type Counts = { open: number; in_progress: number; mine: number; queues: Record<string, number> };

/**
 * The report centre: reports by view (needs attention, unassigned, yours,
 * resolved, rejected, all) and by queue.
 */
export default class ReportCentrePage extends Page {
  counts: Counts | null = null;
  queues: Queue[] = [];

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    if (!app.session.user || !app.forum.attribute('canViewFlags')) {
      m.route.set(app.route('index'));
      return;
    }

    docketNav(t('nav.report_centre'));
    app.setTitle(t('nav.report_centre'));
    app.setTitleCount(0);

    // From a flagged post: straight to its open report, if it has one.
    const post = m.route.param('post');
    if (post) {
      app.store.find<Report[]>('docket-reports', { filter: { post, status: 'open,in_progress' }, page: { limit: 1 } }).then((reports) => {
        if (reports.length) m.route.set(app.route('docket.report', { id: reports[0].id() }), {}, { replace: true });
      });
    }

    app
      .request<Counts>({ method: 'GET', url: app.forum.attribute('apiUrl') + '/docket-counts' })
      .then((counts) => (this.counts = counts))
      .catch(() => {})
      .finally(() => m.redraw());

    app.store
      .find<Queue[]>('docket-queues')
      .then((queues) => (this.queues = queues))
      .catch(() => {})
      .finally(() => m.redraw());
  }

  view() {
    const view = this.currentView();
    const queue = m.route.param('queue');
    const post = m.route.param('post');

    const filter: Record<string, string> = { ...VIEWS[view].filter };
    if (queue) filter.queue = queue;
    if (post) filter.post = post;

    return docketPage('DocketCentrePage', { icon: 'fas fa-clipboard-list', title: t('nav.report_centre'), subtitle: t('centre.subtitle') }, [
      <div className="DocketBar">
        <nav className="DocketBar-views" aria-label={t('nav.views')} oncreate={showActive} onupdate={showActive}>
          <ul>{listItems(this.viewItems().toArray())}</ul>
        </nav>
        <div className="DocketBar-queue">{this.queueSelect()}</div>
      </div>,
      <h2 className="sr-only">{this.heading(view, queue)}</h2>,
      <ReportList filter={filter} />,
    ]);
  }

  currentView(): string {
    const view = m.route.param('view');

    return view && VIEWS[view] ? view : 'active';
  }

  heading(view: string, queue?: string) {
    const name = t(`views.${view}`);
    if (!queue) return name;

    const q = queue === 'none' ? t('queues.general') : this.queues.find((q) => q.id() === queue)?.name();

    return q ? t('views.in_queue', { view: name, queue: q }) : name;
  }

  link(params: Record<string, string | undefined>) {
    const clean: Record<string, string> = {};
    for (const [k, v] of Object.entries(params)) if (v) clean[k] = v;

    return app.route('docket', clean);
  }

  count(n?: number) {
    return n ? <span className="Button-badge">{n}</span> : null;
  }

  /** The lists, across the top: needs attention, unassigned, yours, resolved, rejected, all. */
  viewItems() {
    const items = new ItemList<Mithril.Children>();
    const view = this.currentView();
    const queue = m.route.param('queue');
    const counts = this.counts;

    Object.entries(VIEWS).forEach(([key, def], i) => {
      const n = counts ? { active: counts.open + counts.in_progress, unassigned: counts.open, mine: counts.mine }[key] : undefined;

      items.add(
        key,
        <LinkButton
          className="Button Button--link"
          href={this.link({ view: key === 'active' ? undefined : key, queue })}
          icon={def.icon}
          active={view === key}
          aria-current={view === key ? 'page' : undefined}
        >
          {t(`views.${key}`)}
          {this.count(n)}
        </LinkButton>,
        100 - i
      );
    });

    return items;
  }

  /** Which queue: all of them, the general queue, or one of the forum's. */
  queueItems() {
    const items = new ItemList<Mithril.Children>();
    const view = this.currentView();
    const queue = m.route.param('queue');
    const counts = this.counts;
    const v = view === 'active' ? undefined : view;

    items.add(
      'queue-all',
      <LinkButton href={this.link({ view: v })} icon="fas fa-layer-group" active={!queue}>
        {t('queues.all')}
      </LinkButton>,
      40
    );

    items.add(
      'queue-none',
      <LinkButton href={this.link({ view: v, queue: 'none' })} icon="fas fa-folder-open" active={queue === 'none'}>
        {t('queues.general')}
        {this.count(counts?.queues['0'])}
      </LinkButton>,
      39
    );

    this.queues.forEach((q, i) => {
      items.add(
        `queue-${q.id()}`,
        <LinkButton
          href={this.link({ view: v, queue: q.id() })}
          icon="fas fa-circle"
          className={'DocketQueueLink' + (q.color() ? ' DocketQueueLink--colored' : '')}
          style={q.color() ? { '--docket-queue': q.color() } : undefined}
          active={queue === q.id()}
        >
          {q.name()}
          {this.count(counts?.queues[q.id()!])}
        </LinkButton>,
        38 - i
      );
    });

    return items;
  }

  queueSelect() {
    return (
      <SelectDropdown
        buttonClassName="Button"
        className="DocketBar-queueSelect"
        menuClassName="Dropdown-menu--right"
        accessibleToggleLabel={t('nav.queue_toggle')}
      >
        {this.queueItems().toArray()}
      </SelectDropdown>
    );
  }
}
