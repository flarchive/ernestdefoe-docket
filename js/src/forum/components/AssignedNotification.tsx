import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import username from 'flarum/common/helpers/username';
import { t } from '../t';

export default class AssignedNotification extends Notification {
  icon() {
    return 'fas fa-clipboard-list';
  }

  href() {
    const report = this.attrs.notification.subject() || null;

    return report ? app.route('docket.report', { id: report.id() }) : app.route('docket');
  }

  content() {
    const report = this.attrs.notification.subject() || null;
    const from = this.attrs.notification.fromUser();
    const id = report?.id();

    return from ? t('notifications.assigned_by', { id, username: username(from) }) : t('notifications.assigned_by_rule', { id });
  }

  excerpt() {
    return null;
  }
}
