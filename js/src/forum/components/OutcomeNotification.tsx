import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import { t } from '../t';

export default class OutcomeNotification extends Notification {
  icon() {
    return 'fas fa-flag-checkered';
  }

  href() {
    return app.route('docket.mine');
  }

  content() {
    const status = (this.attrs.notification.content() as any)?.status;

    return t(status === 'resolved' ? 'notifications.outcome_resolved' : 'notifications.outcome_rejected');
  }

  excerpt() {
    return (this.attrs.notification.subject() as any)?.outcome?.() || null;
  }
}
