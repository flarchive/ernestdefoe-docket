import app from 'flarum/forum/app';
import UserPage from 'flarum/forum/components/UserPage';
import type Mithril from 'mithril';
import ReportList from './ReportList';
import { t } from '../t';

/**
 * A member's report history, for staff: every report about their posts.
 */
export default class UserReportsPage extends UserPage {
  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    this.loadUser(m.route.param('username'));
  }

  content() {
    if (!this.user || !app.forum.attribute('canViewFlags')) return null;

    return (
      <div className="DocketUserPage">
        <ReportList filter={{ user: this.user.id()! }} empty={<p className="DocketHistory-empty">{t('report.user_history_empty')}</p>} />
      </div>
    );
  }
}
