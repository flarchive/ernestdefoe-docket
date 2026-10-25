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
    .setting(() => ({
      setting: 'ernestdefoe-docket.my_reports_days',
      type: 'select',
      label: t('settings.my_reports_days'),
      help: t('settings.my_reports_days_help'),
      default: '0',
      options: {
        0: t('settings.my_reports_forever'),
        30: t('settings.my_reports_30'),
        90: t('settings.my_reports_90'),
        180: t('settings.my_reports_180'),
        365: t('settings.my_reports_365'),
      },
    }))
    .customSetting(() => <DocketSettings />)
    .permission(() => ({ icon: 'fas fa-user-plus', label: t('permissions.assign_others'), permission: 'docket.assignOthers' }), 'moderate'),
];
