import app from 'flarum/forum/app';
import UserPage from 'flarum/forum/components/UserPage';
import LinkButton from 'flarum/common/components/LinkButton';
import type Mithril from 'mithril';
import ReportList from './ReportList';
import ReporterReports from './ReporterReports';
import { t } from '../t';

/**
 * A member's report history, for staff: the reports about their posts, and
 * the reports they made.
 */
export default class UserReportsPage extends UserPage {
  made = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    this.made = /\/reports-made\/?$/.test(m.route.get().split('?')[0]);
    this.loadUser(m.route.param('username'));
  }

  content() {
    if (!this.user || !app.forum.attribute('canViewFlags')) return null;

    const slug = this.user.slug();

    return (
      <div className="DocketUserPage">
        <nav className="DocketTabs" aria-label={t('user.tabs')}>
          <LinkButton
            className={'Button' + (this.made ? '' : ' active')}
            aria-current={this.made ? undefined : 'page'}
            href={app.route('user.docket', { username: slug })}
          >
            {t('user.about')}
          </LinkButton>
          <LinkButton
            className={'Button' + (this.made ? ' active' : '')}
            aria-current={this.made ? 'page' : undefined}
            href={app.route('user.docket.made', { username: slug })}
          >
            {t('user.made')}
          </LinkButton>
        </nav>
        {this.made ? (
          <ReporterReports user={this.user} />
        ) : (
          <ReportList filter={{ user: this.user.id()! }} empty={<p className="DocketHistory-empty">{t('report.user_history_empty')}</p>} />
        )}
      </div>
    );
  }
}
