/**
 * Run `done` with what an app.request or app.store call returned. When the
 * call fails, Flarum has already shown its error alert, so the rejection is
 * handled here: the screen stays as it was, for the member to try again.
 * Anything `done` throws or rejects with is not caught.
 */
export default function settle<T>(request: Promise<T>, done: (value: T) => unknown): Promise<unknown> {
  return request.then(done, () => undefined);
}
