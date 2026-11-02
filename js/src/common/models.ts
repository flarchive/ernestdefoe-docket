import Model from 'flarum/common/Model';
import type Post from 'flarum/common/models/Post';
import type User from 'flarum/common/models/User';
import type Group from 'flarum/common/models/Group';

export type Status = 'open' | 'in_progress' | 'resolved' | 'rejected';

/** What crowd moderation did to a report's post. */
export type CrowdState = 'hidden' | 'approval' | 'restored' | 'held' | null;

export class Queue extends Model {
  name = Model.attribute<string>('name');
  description = Model.attribute<string | null>('description');
  color = Model.attribute<string | null>('color');
  position = Model.attribute<number>('position');
  isRestricted = Model.attribute<boolean>('isRestricted');
  crowdThreshold = Model.attribute<number | null>('crowdThreshold');
  groups = Model.hasMany<Group>('groups');
}

/** Where a post is: its discussion's slug and title, and the post's number. */
export type Place = { slug: string; title: string; number: number } | null;

export class Entry extends Model {
  type = Model.attribute<string>('type');
  reason = Model.attribute<string | null>('reason');
  reasonDetail = Model.attribute<string | null>('reasonDetail');
  createdAt = Model.attribute<Date | undefined, string>('createdAt', Model.transformDate);
  reportStatus = Model.attribute<Status | 'closed'>('reportStatus');
  outcome = Model.attribute<string | null>('outcome');
  user = Model.hasOne<User | null>('user');
  place = Model.attribute<Place>('post');
  /** Staff listing a member's reports only: the report, and how it really went. */
  reportId = Model.attribute<number | undefined>('reportId');
  verdict = Model.attribute<Verdict | undefined>('verdict');
}

/** How a report really went: its status, or restored when staff put back what the crowd hid. */
export type Verdict = Status | 'restored';

/** A member's record over one time frame: see ReporterStats. */
export type FrameCounts = { total: number; resolved: number; rejected: number; open: number };
export type Frame = '30d' | '90d' | '12m' | 'all';
export type ReporterRecord = { [frame in Frame]: FrameCounts };

export class ReportEvent extends Model {
  type = Model.attribute<string>('type');
  /** The `data` attribute: `data` itself is the model's resource object. */
  details = Model.attribute<Record<string, any> | null>('data');
  body = Model.attribute<string | null>('body');
  createdAt = Model.attribute<Date | undefined, string>('createdAt', Model.transformDate);
  user = Model.hasOne<User | null>('user');
}

export class Report extends Model {
  status = Model.attribute<Status>('status');
  outcome = Model.attribute<string | null>('outcome');
  reportersNotified = Model.attribute<boolean>('reportersNotified');
  entryCount = Model.attribute<number>('entryCount');
  place = Model.attribute<Place>('discussion');
  createdAt = Model.attribute<Date | undefined, string>('createdAt', Model.transformDate);
  updatedAt = Model.attribute<Date | undefined, string>('updatedAt', Model.transformDate);
  closedAt = Model.attribute<Date | null | undefined, string | null>('closedAt', Model.transformDate);
  canAssignOthers = Model.attribute<boolean>('canAssignOthers');
  crowdState = Model.attribute<CrowdState>('crowdState');
  post = Model.hasOne<Post | null>('post');
  user = Model.hasOne<User | null>('user');
  assignee = Model.hasOne<User | null>('assignee');
  queue = Model.hasOne<Queue | null>('queue');
  closedBy = Model.hasOne<User | null>('closedBy');
  entries = Model.hasMany<Entry>('entries');
  events = Model.hasMany<ReportEvent>('events');

  isActive() {
    return this.status() === 'open' || this.status() === 'in_progress';
  }

  /** The crowd hid the post (or sent it to approval) and staff haven't undone it. */
  crowdActed() {
    const state = this.crowdState();
    return state === 'hidden' || state === 'approval';
  }
}

export class Rule extends Model {
  name = Model.attribute<string>('name');
  position = Model.attribute<number>('position');
  isEnabled = Model.attribute<boolean>('isEnabled');
  conditions = Model.attribute<{ tags: number[]; reasons: string[]; types: string[]; groups: number[] } | null>('conditions');
  queue = Model.hasOne<Queue | null>('queue');
  assignee = Model.hasOne<User | null>('assignee');
  assigneeGroup = Model.hasOne<Group | null>('assigneeGroup');
}
