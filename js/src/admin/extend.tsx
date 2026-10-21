import Extend from 'flarum/common/extenders';
import { Queue, Rule } from '../common/models';
import DocketSettings from './components/DocketSettings';
import { t } from './t';

export default [
  new Extend.Store().add('docket-queues', Queue).add('docket-rules', Rule),

  new Extend.Admin()
    .setting(() => ({
      setting: 'ernestdefoe-docket.notify_by_default',
      type: 'boolean',
      label: t('settings.notify_by_default'),
      help: t('settings.notify_by_default_help'),
    }))
    .customSetting(() => <DocketSettings />)
    .permission(() => ({ icon: 'fas fa-user-plus', label: t('permissions.assign_others'), permission: 'docket.assignOthers' }), 'moderate'),
];
