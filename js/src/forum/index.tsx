import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import SessionDropdown from 'flarum/forum/components/SessionDropdown';
import UserPage from 'flarum/forum/components/UserPage';
import Post from 'flarum/forum/components/Post';
import FlagList from 'ext:flarum/flags/forum/components/FlagList';
import { t } from './t';

export { default as extend } from './extend';

const isStaff = () => !!app.session.user && !!app.forum.attribute('canViewFlags');

app.initializers.add('ernestdefoe-docket', () => {
  extend(IndexSidebar.prototype, 'navItems', (items) => {
    if (!isStaff()) return;

    items.add(
      'docket',
      <LinkButton href={app.route('docket')} icon="fas fa-clipboard-list">
        {t('nav.report_centre')}
      </LinkButton>,
      60
    );
  });

  extend(SessionDropdown.prototype, 'items', (items) => {
    if (!app.session.user) return;

    items.add(
      'docket',
      <LinkButton href={app.route('docket.mine')} icon="fas fa-flag">
        {t('nav.my_reports')}
      </LinkButton>,
      55
    );
  });

  // A member's report history, for staff, on their profile.
  extend(UserPage.prototype, 'navItems', function (this: UserPage, items) {
    if (!isStaff() || !this.user) return;

    items.add(
      'docket',
      <LinkButton href={app.route('user.docket', { username: this.user.slug() })} icon="fas fa-clipboard-list">
        {t('nav.user_reports')}
      </LinkButton>,
      5
    );
  });

  // From the flags dropdown and a flagged post's bar to the report centre.
  extend(FlagList.prototype, 'controlItems', (items) => {
    items.add(
      'docket',
      <LinkButton className="Button Button--link" icon="fas fa-clipboard-list" href={app.route('docket')} aria-label={t('nav.report_centre')}>
        {t('nav.report_centre')}
      </LinkButton>
    );
  });

  extend(Post.prototype as any, 'flagActionItems' as any, function (this: Post, items: any) {
    const post = this.attrs.post;

    items.add(
      'docket',
      <Button className="Button" icon="fas fa-clipboard-list" onclick={() => m.route.set(app.route('docket', { post: post.id() }))}>
        {t('post.open_report')}
      </Button>,
      -50
    );
  });

  // 🚨 By module name: NotificationGrid is in a lazy chunk in Flarum 2, so a
  // static import of it is undefined when initializers run.
  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', (items: any) => {
    if (isStaff()) {
      items.add('docketAssigned', { name: 'docketAssigned', icon: 'fas fa-clipboard-list', label: t('settings.notify_assigned') });
    }

    items.add('docketOutcome', { name: 'docketOutcome', icon: 'fas fa-flag-checkered', label: t('settings.notify_outcome') });
  });
});
