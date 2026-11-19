import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Avatar from 'flarum/common/components/Avatar';
import Button from 'flarum/common/components/Button';
import Dropdown from 'flarum/common/components/Dropdown';
import Link from 'flarum/common/components/Link';
import LinkButton from 'flarum/common/components/LinkButton';
import Select from 'flarum/common/components/Select';
import UserSelectionModal from 'flarum/common/components/UserSelectionModal';
import username from 'flarum/common/helpers/username';
import humanTime from 'flarum/common/helpers/humanTime';
import Stream from 'flarum/common/utils/Stream';
import PostControls from 'flarum/forum/utils/PostControls';
import type Mithril from 'mithril';
import type Post from 'flarum/common/models/Post';
import type User from 'flarum/common/models/User';
import type { Entry, Queue, Report, ReportEvent, ReporterRecord } from '../../common/models';
import { updateReport } from '../api';
import { statusBadge, eventText, reasonLabel } from '../labels';
import { recordLine } from '../record';
import CloseReportModal from './CloseReportModal';
import ReportList from './ReportList';
import { t } from '../t';
import { docketNav, docketPage } from './DocketHero';
import extractText from 'flarum/common/utils/extractText';
import settle from '../../common/settle';

/**
 * One report: the post, who reported it and why, who holds it, its queue
 * and status, the timeline with staff notes, and the history of the post
 * and its author.
 */
/** The API sends the newest first (a busy report shows only its newest): listed in the order they happened. */
const oldestFirst = <T extends { id(): string | undefined }>(models: T[]): T[] => [...models].sort((a, b) => Number(a.id()) - Number(b.id()));

export default class ReportPage extends Page {
  report: Report | null = null;
  /** Each reporter's record, by user id, from the report's `meta`. */
  records: Record<string, ReporterRecord> = {};
  queues: Queue[] = [];
  /** Whose earlier reports the side shows, when several people reported the post. */
  reporterId: string | null = null;
  note = Stream('');
  saving = false;
  busy = false;
  /** The post as last loaded: when it changes (hidden, restored, deleted), the report is loaded again. */
  postStamp: number | null = null;
  /** A modal was open (Edit, Warn): reload once it closes. */
  modalWasOpen = false;
  loading = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    if (!app.session.user || !app.forum.attribute('canViewFlags')) {
      m.route.set(app.route('index'));
      return;
    }

    docketNav(t('nav.report_centre'));
    this.load();

    settle(app.store.find<Queue[]>('docket-queues'), (queues) => (this.queues = queues)).finally(() => m.redraw());
  }

  onupdate(vnode: Mithril.VnodeDOM) {
    super.onupdate(vnode);

    // Show what the moderation controls did once they have done it: the
    // post they change is pushed to the store again, and the ones that open
    // a modal are done when it closes.
    const modalOpen = app.modal.isModalOpen();
    const closed = this.modalWasOpen && !modalOpen;
    this.modalWasOpen = modalOpen;

    if (this.report && !this.loading && (closed || this.stamp() !== this.postStamp)) this.load();
  }

  /** When the post was last pushed to the store; -1 once it is deleted. */
  stamp(): number | null {
    const post = this.report?.post() || null;
    return post ? (post.exists ? post.freshness.getTime() : -1) : null;
  }

  load() {
    this.loading = true;

    return settle(app.store.find<Report>('docket-reports', m.route.param('id')), (report) => {
      this.report = report;
      this.records = (report as any).payload?.meta?.reporterStats || {};
      app.setTitle(extractText(t('report.title', { id: report.id() })));
    }).finally(() => {
      // Also when it failed: the alert is shown once, not on every redraw.
      this.postStamp = this.stamp();
      this.loading = false;
      m.redraw();
    });
  }

  view() {
    const report = this.report;

    if (!report) return docketPage('DocketReportPage', { icon: 'fas fa-clipboard-list', title: t('nav.report_centre') }, null, true);

    const post = report.post();

    return docketPage(
      'DocketReportPage',
      {
        icon: 'fas fa-clipboard-list',
        title: [t('report.title', { id: report.id() }), ' ', statusBadge(report.status())],
        subtitle: t('report.opened', { time: humanTime(report.createdAt()!) }),
      },
      <div className="DocketReport">
        <div className="DocketReport-toolbar">
          <LinkButton className="Button" icon="fas fa-arrow-left" href={app.route('docket')}>
            {t('report.back')}
          </LinkButton>
        </div>

        <div className="DocketReport-grid">
          <div className="DocketReport-main">
            {this.crowdBanner(report)}
            {post ? this.postCard(post) : <div className="DocketCard DocketCard--muted">{t('report.post_gone')}</div>}
            {this.reporters(report)}
            {this.timeline(report)}
          </div>
          <aside className="DocketReport-side">
            {this.controls(report)}
            {this.history(report)}
          </aside>
        </div>
      </div>
    );
  }

  /** What the community's reports did, and the one-click way back. */
  crowdBanner(report: Report) {
    const state = report.crowdState();
    if (!state || state === 'restored') return null;

    if (state === 'held') {
      return (
        <div className="DocketCrowd DocketCrowd--held">
          <i className="fas fa-users" aria-hidden="true" /> <span>{t('crowd.held')}</span>
        </div>
      );
    }

    return (
      <div className="DocketCrowd">
        <i className="fas fa-users-slash" aria-hidden="true" />
        <span className="DocketCrowd-text">{t(`crowd.banner_${state}`)}</span>
        {report.isActive() ? (
          <Button className="Button Button--primary" icon="fas fa-rotate-left" loading={this.busy} onclick={() => this.restore()}>
            {t('crowd.restore')}
          </Button>
        ) : null}
      </div>
    );
  }

  /** Reject the report, which puts the post back. */
  restore() {
    if (!this.report) return;
    this.busy = true;

    const putBack = this.putBack(this.report);

    settle(updateReport(this.report, { status: 'rejected' }), () => {
      putBack();
      this.dropFlagCounts();
      return this.load();
    }).finally(() => {
      this.busy = false;
      m.redraw();
    });
  }

  /**
   * Rejecting what the crowd hid restores the post. Core leaves isHidden out
   * of a post that isn't hidden, so a reload can't clear it: this does.
   */
  putBack(report: Report) {
    const post = report.crowdActed() ? report.post() || null : null;

    return () => {
      const back = { isHidden: false, hiddenAt: null, isApproved: true };
      post?.pushAttributes(back);
      (post?.discussion() || null)?.pushAttributes(back);
    };
  }

  /** A report's flags were dismissed with it: the flags dropdown and its count are out of date. */
  dropFlagCounts() {
    (app as any).flags?.clear();
    const count = app.session.user?.attribute<number>('newFlagCount');
    if (count) app.session.user!.pushAttributes({ newFlagCount: count - 1 });
    const total = app.forum.attribute<number>('flagCount');
    if (total) app.forum.pushAttributes({ flagCount: total - 1 });
  }

  postCard(post: Post) {
    const user = post.user();
    const discussion = post.discussion();

    // Hide, delete, edit, and fof/moderator-warnings' Warn, as on the post.
    const controls = [...PostControls.moderationControls(post, this).toArray(), ...PostControls.destructiveControls(post, this).toArray()];

    return (
      <div className={'DocketCard DocketPost' + (post.isHidden() ? ' DocketPost--hidden' : '')}>
        <div className="DocketPost-head">
          <Avatar user={user || null} />
          <div className="DocketPost-who">
            {user ? <Link href={app.route.user(user)}>{username(user)}</Link> : username(null)}
            <div className="DocketPost-where">
              {discussion ? <Link href={app.route.post(post)}>{discussion.title()}</Link> : null} · {humanTime(post.createdAt()!)}
            </div>
          </div>
          {controls.length ? (
            <Dropdown
              className="DocketPost-controls"
              buttonClassName="Button"
              label={t('report.moderate')}
              icon="fas fa-gavel"
              accessibleToggleLabel={t('report.moderate')}
            >
              {controls}
            </Dropdown>
          ) : null}
        </div>
        {post.isHidden() ? <div className="DocketPost-flag">{t('report.post_hidden')}</div> : null}
        <div className="Post-body DocketPost-body">{m.trust(post.contentHtml() || '')}</div>
        {this.warnings(user)}
      </div>
    );
  }

  /** fof/moderator-warnings: the author's warning count, linked. */
  warnings(user: User | null | false | undefined) {
    if (!user || !('fof-moderator-warnings' in flarum.extensions)) return null;

    const count = user.attribute<number | undefined>('visibleWarningCount');
    if (count === undefined) return null;

    return (
      <div className="DocketPost-warnings">
        <i className="fas fa-exclamation-circle" aria-hidden="true" />{' '}
        <Link href={app.route('user.warnings', { username: user.slug() })}>{t('report.warning_count', { count })}</Link>
      </div>
    );
  }

  reporters(report: Report) {
    const entries = oldestFirst((report.entries() || []).filter(Boolean) as Entry[]);

    return (
      <section className="DocketCard">
        <h3 className="DocketCard-title">{t('report.reporters', { count: report.entryCount() || entries.length })}</h3>
        <ul className="DocketEntries">
          {entries.map((entry) => {
            const user = entry!.user();
            const record = user && entry!.type() === 'user' ? this.records[user.id()!] : null;

            return (
              <li className="DocketEntry">
                <Avatar user={user || null} />
                <div>
                  <div className="DocketEntry-head">
                    <strong>{user ? username(user) : t('report.system')}</strong> · {reasonLabel(entry!)} · {humanTime(entry!.createdAt()!)}
                  </div>
                  {entry!.reasonDetail() ? <div className="DocketEntry-detail">{entry!.reasonDetail()}</div> : null}
                  {record && user ? (
                    <Link className="DocketEntry-record" href={app.route('user.docket.made', { username: user.slug() })}>
                      {recordLine(record.all)}
                    </Link>
                  ) : null}
                </div>
              </li>
            );
          })}
        </ul>
      </section>
    );
  }

  controls(report: Report) {
    const active = report.isActive();

    return (
      <section className="DocketCard DocketControls">
        {this.holderRow(report)}

        <div className="DocketControls-row">
          <label className="DocketControls-label" for="docket-queue">
            {t('report.queue')}
          </label>
          {active ? (
            <Select
              id="docket-queue"
              value={(report.queue() || null)?.id() || ''}
              disabled={this.busy}
              options={this.queueOptions(report)}
              onchange={(id: string) => this.change({}, { queue: id ? this.queues.find((q) => q.id() === id) || null : null })}
            />
          ) : (
            <span>{(report.queue() || null)?.name() || t('queues.general')}</span>
          )}
        </div>

        {this.actions(report, active)}

        {!active && report.reportersNotified() ? (
          <div className="DocketControls-told">
            <i className="fas fa-envelope-circle-check" aria-hidden="true" /> {t('report.reporters_told')}
          </div>
        ) : null}
      </section>
    );
  }

  /** Who holds the report, and claiming, releasing or handing it over. */
  holderRow(report: Report) {
    const me = app.session.user!;
    const assignee = report.assignee();
    const mine = !!assignee && assignee.id() === me.id();
    const mayHandOver = report.canAssignOthers();
    const active = report.isActive();
    return (
      <div className="DocketControls-row">
        <div className="DocketControls-label">{t(active ? 'report.held_by' : 'report.handled_by')}</div>
        <div className="DocketControls-holder">
          {assignee ? (
            <span className="DocketControls-user">
              <Avatar user={assignee} /> {username(assignee)}
            </span>
          ) : (
            <span className="DocketControls-none">{t('report.no_one')}</span>
          )}
        </div>
        {active ? (
          <div className="DocketControls-buttons">
            {!mine && (!assignee || mayHandOver) ? (
              <Button className="Button Button--primary" icon="fas fa-hand" loading={this.busy} onclick={() => this.change({}, { assignee: me })}>
                {t(assignee ? 'report.take_over' : 'report.claim')}
              </Button>
            ) : null}
            {assignee && (mine || mayHandOver) ? (
              <Button className="Button" icon="fas fa-hand-point-left" disabled={this.busy} onclick={() => this.change({}, { assignee: null })}>
                {t(mine ? 'report.release' : 'report.unassign')}
              </Button>
            ) : null}
            {mayHandOver ? (
              <Button className="Button" icon="fas fa-user-plus" disabled={this.busy} onclick={() => this.assign(report)}>
                {t('report.assign')}
              </Button>
            ) : null}
          </div>
        ) : null}
      </div>
    );
  }

  /** Resolve or reject, or reopen once closed. */
  actions(report: Report, active: boolean) {
    return (
      <div className="DocketControls-actions">
        {active ? (
          [
            <Button
              className="Button DocketControls-resolve"
              icon="fas fa-circle-check"
              disabled={this.busy}
              onclick={() => this.close(report, 'resolved')}
            >
              {t('report.resolve')}
            </Button>,
            <Button
              className="Button DocketControls-reject"
              icon="fas fa-circle-xmark"
              disabled={this.busy}
              onclick={() => this.close(report, 'rejected')}
            >
              {t('report.reject')}
            </Button>,
          ]
        ) : (
          <Button className="Button" icon="fas fa-rotate-left" loading={this.busy} onclick={() => this.change({ status: 'in_progress' })}>
            {t('report.reopen')}
          </Button>
        )}
      </div>
    );
  }

  queueOptions(report: Report) {
    const options: Record<string, string> = { '': extractText(t('queues.general')) };
    this.queues.forEach((q) => (options[q.id()!] = q.name()));

    // The report's own queue even when it is not in the list loaded.
    const current = report.queue() || null;
    if (current && !options[current.id()!]) options[current.id()!] = current.name();

    return options;
  }

  change(attributes: Record<string, unknown>, relationships: { assignee?: User | null; queue?: Queue | null } = {}) {
    if (!this.report) return;
    this.busy = true;

    settle(updateReport(this.report, attributes, relationships), () => this.load()).finally(() => {
      this.busy = false;
      m.redraw();
    });
  }

  assign(report: Report) {
    app.modal.show(UserSelectionModal, {
      title: t('report.assign_title', { id: report.id() }),
      selected: report.assignee() ? [report.assignee()!] : [],
      maxItems: 1,
      onsubmit: (users: User[]) => this.change({}, { assignee: users[0] || null }),
    });
  }

  close(report: Report, status: 'resolved' | 'rejected') {
    const putBack = this.putBack(report);

    app.modal.show(CloseReportModal, {
      report,
      status,
      onclosed: () => {
        if (status === 'rejected') putBack();
        this.dropFlagCounts();
        this.load();
      },
    });
  }

  timeline(report: Report) {
    const events = oldestFirst((report.events() || []).filter(Boolean) as ReportEvent[]);

    return (
      <section className="DocketCard">
        <h3 className="DocketCard-title">{t('report.timeline')}</h3>
        <ol className="DocketTimeline">
          {events.map((event) =>
            event.type() === 'comment' ? (
              <li className="DocketTimeline-item DocketTimeline-item--note">
                <Avatar user={event.user() || null} />
                <div className="DocketTimeline-note">
                  <div className="DocketTimeline-meta">
                    <strong>{username(event.user() || null)}</strong> · {humanTime(event.createdAt()!)}
                  </div>
                  <div className="DocketTimeline-body">{event.body()}</div>
                </div>
              </li>
            ) : (
              <li className="DocketTimeline-item">
                <span className="DocketTimeline-dot" aria-hidden="true" />
                <div>
                  {eventText(event)} <span className="DocketTimeline-time">{humanTime(event.createdAt()!)}</span>
                  {event.body() ? <blockquote className="DocketTimeline-outcome">{event.body()}</blockquote> : null}
                </div>
              </li>
            )
          )}
          {!events.length ? <li className="DocketTimeline-empty">{t('report.timeline_empty')}</li> : null}
        </ol>
        <form className="DocketNote" onsubmit={(e: SubmitEvent) => this.addNote(e, report)}>
          <label className="sr-only" for="docket-note">
            {t('report.note_label')}
          </label>
          <textarea id="docket-note" className="FormControl" rows={3} maxlength={5000} placeholder={t('report.note_placeholder')} bidi={this.note} />
          <div className="DocketNote-foot">
            <span className="helpText">{t('report.note_help')}</span>
            <Button type="submit" className="Button Button--primary" loading={this.saving} disabled={!this.note().trim()}>
              {t('report.note_submit')}
            </Button>
          </div>
        </form>
      </section>
    );
  }

  addNote(e: SubmitEvent, report: Report) {
    e.preventDefault();
    if (!this.note().trim()) return;
    this.saving = true;

    settle(app.store.createRecord('docket-events').save({ body: this.note(), relationships: { report } }), () => {
      this.note('');
      return this.load();
    }).finally(() => {
      this.saving = false;
      m.redraw();
    });
  }

  history(report: Report) {
    const post = report.post();
    const user = report.user();

    return [
      post ? (
        <section className="DocketCard DocketHistory">
          <h3 className="DocketCard-title">{t('report.post_history')}</h3>
          <ReportList
            filter={{ post: post.id()! }}
            except={report.id()}
            limit={5}
            brief
            empty={<p className="DocketHistory-empty">{t('report.post_history_empty')}</p>}
          />
        </section>
      ) : null,
      user ? (
        <section className="DocketCard DocketHistory">
          <h3 className="DocketCard-title">{t('report.user_history', { name: username(user) })}</h3>
          <ReportList
            filter={{ user: user.id()! }}
            except={report.id()}
            limit={5}
            brief
            empty={<p className="DocketHistory-empty">{t('report.user_history_empty')}</p>}
          />
          <LinkButton className="Button Button--link" href={app.route('user.docket', { username: user.slug() })}>
            {t('report.user_history_all')}
          </LinkButton>
        </section>
      ) : null,
      this.reporterHistory(report),
    ];
  }

  /** What the people who reported this post have reported before. */
  reporterHistory(report: Report) {
    const reporters: User[] = [];

    for (const entry of report.entries() || []) {
      const user = entry && entry.type() === 'user' ? entry.user() : null;
      if (user && !reporters.some((r) => r.id() === user.id())) reporters.push(user);
    }

    if (!reporters.length) return null;

    const reporter = reporters.find((r) => r.id() === this.reporterId) || reporters[0];

    return (
      <section className="DocketCard DocketHistory">
        <h3 className="DocketCard-title">{t('report.reporter_history', { name: username(reporter) })}</h3>
        {reporters.length > 1 ? (
          <Select
            className="DocketHistory-pick"
            value={reporter.id()}
            options={Object.fromEntries(reporters.map((r) => [r.id(), extractText(username(r))]))}
            onchange={(id: string) => (this.reporterId = id)}
            aria-label={extractText(t('report.reporter_history_pick'))}
          />
        ) : null}
        <ReportList
          filter={{ reporter: reporter.id()! }}
          except={report.id()}
          limit={5}
          brief
          empty={<p className="DocketHistory-empty">{t('report.reporter_history_empty')}</p>}
        />
        <LinkButton className="Button Button--link" href={app.route('user.docket.made', { username: reporter.slug() })}>
          {t('report.user_history_all')}
        </LinkButton>
      </section>
    );
  }
}
