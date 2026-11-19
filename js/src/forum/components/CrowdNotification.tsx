import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import { t } from '../t';

/** To an author: the community's reports hid their post until staff look, or staff put it back. */
export default class CrowdNotification extends Notification {
  icon() {
    return this.content_().state === 'restored' ? 'fas fa-rotate-left' : 'fas fa-users-slash';
  }

  href() {
    const post = this.attrs.notification.subject() as any;

    return post?.discussion?.() ? app.route.post(post) : app.route('index');
  }

  content() {
    const { state, reason } = this.content_();
    if (state === 'restored') return t('notifications.crowd_restored');

    // The reason as the flag form words it.
    const key = `flarum-flags.forum.flag_post.reason_${reason}_label`;
    const label = reason && reason !== 'other' ? app.translator.trans(key) : key;

    return t(`notifications.crowd_${state === 'approval' ? 'approval' : 'hidden'}`, { reason: label === key ? t('reason.other') : label });
  }

  excerpt() {
    return null;
  }

  content_(): { state?: string; reason?: string | null } {
    return (this.attrs.notification.content() as any) || {};
  }
}
