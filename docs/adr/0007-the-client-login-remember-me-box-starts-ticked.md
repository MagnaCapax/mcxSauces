# ADR-0007: The client login "Remember me" box starts ticked

## Metadata
- Author: Väinämöinen
- Date: 2026-10-23
- Status: Accepted

## Context
Customers were logged out of the client area within hours and asked for logins that last weeks. A client-area
login without "Remember me" lives only in the PHP session cookie. That cookie has no expiry date, so the login
ends when the browser closes, when the server's idle-session cleanup removes the session, or when the client's
own IP address changes, because WHMCS binds a session to the IP. WHMCS already offers persistent logins: with
"Remember me" ticked it sets a login cookie that lasts 365 days or until logout. The stock login form leaves the
box unticked, so almost nobody got it.

## Options Considered
- **Tick the existing box by default with a footer-output hook.** Uses WHMCS's own persistent login, keeps the
  client's choice (the box can be unticked on a shared computer), edits no template, so updates do not undo it.
  Chosen.
- **Add `checked` to the login template.** The same effect, but template edits are overwritten on every WHMCS
  update and a template change once broke the whole client area. Rejected.
- **Force "Remember me" on every login server-side.** Removes the client's choice and leaves a year-long login on
  shared computers. Rejected.
- **Raise the PHP session lifetime to weeks.** Applies to the admin area too, keeps millions of mostly anonymous
  session files, and the login still ends on an IP change. Rejected.
- **Turn off the session IP check.** Weakens session-theft protection and does nothing for browser restarts.
  Rejected.

## Decision
- `whmcs/hooks/loginRememberMeDefault.php` adds a constant script on `ClientAreaFooterOutput` that ticks every
  checkbox named `rememberme` after the page loads. Nothing from the request reaches the output.
- The box stays visible. Logout ends the persistent login as before. The admin area is not touched.

## Consequences
- Clients who log in normally stay logged in across browser restarts, idle time and IP changes, for up to a year
  or until they log out.
- A client who logs in on a shared computer and forgets to untick the box stays logged in there. The box is
  visible on the form, and logging out clears it.
- Without JavaScript the box stays unticked, which is the behaviour before this change.

## References
- WHMCS cookie documentation: the remember-me cookie is persistent for 365 days or until logout.
- ADR-0001 for the hook layout rules.
