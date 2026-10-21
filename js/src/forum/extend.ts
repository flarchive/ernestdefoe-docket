import Extend from 'flarum/common/extenders';
import { Entry, Queue, Report, ReportEvent } from '../common/models';
import AssignedNotification from './components/AssignedNotification';
import CrowdNotification from './components/CrowdNotification';
import OutcomeNotification from './components/OutcomeNotification';

export default [
  new Extend.Store().add('docket-reports', Report).add('docket-entries', Entry).add('docket-events', ReportEvent).add('docket-queues', Queue),

  new Extend.Notification()
    .add('docketAssigned', AssignedNotification)
    .add('docketOutcome', OutcomeNotification)
    .add('docketCrowd', CrowdNotification),

  // The pages load on demand: nothing of them is on any other page.
  new Extend.Routes()
    .add('docket', '/reports', () => import('./components/ReportCentrePage'))
    .add('docket.report', '/reports/:id', () => import('./components/ReportPage'))
    .add('docket.mine', '/my-reports', () => import('./components/MyReportsPage'))
    .add('user.docket', '/u/:username/reports', () => import('./components/UserReportsPage')),
];
