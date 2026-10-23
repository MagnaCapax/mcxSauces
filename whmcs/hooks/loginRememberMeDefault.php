<?php
/**
 * WHMCS Hook: the "Remember me" box on the client login form starts ticked.
 *
 * WHY THIS EXISTS
 * ---------------
 * Without "Remember me" a client-area login lives only in the PHP session cookie, which has no
 * expiry date: it ends when the browser closes, when the server's idle-session cleanup removes the
 * session, or when the client's own IP address changes (WHMCS binds a session to the IP). Customers
 * were logged out within hours. WHMCS already has the fix: with "Remember me" ticked it sets a
 * persistent login cookie that lasts 365 days or until logout. The box was simply unticked by
 * default, so almost nobody got it. ADR-0007.
 *
 * WHAT THIS HOOK DOES
 * -------------------
 * On ClientAreaFooterOutput it adds a small constant script that ticks every checkbox named
 * "rememberme" once the page has loaded. The box stays visible, so a client on a shared computer
 * can untick it. Nothing from the request is echoed. Without JavaScript the box simply stays
 * unticked, as before. The template files are not edited, so a WHMCS update does not undo this.
 *
 * Deploy to:  <whmcs_root>/includes/hooks/loginRememberMeDefault.php
 * Ownership:  same as the sibling hooks (644)
 * Test:       php whmcs/hooks/tests/loginRememberMeDefaultTest.php
 *
 * SPDX-License-Identifier: Apache-2.0
 */
declare(strict_types=1);

/** The footer script: ticks every rememberme checkbox on the page, and does nothing where there is none. */
function pmLoginRememberMeDefaultScript(): string
{
    return '<script>document.addEventListener("DOMContentLoaded",function(){'
        . 'var boxes=document.querySelectorAll(\'input[type="checkbox"][name="rememberme"]\');'
        . 'for(var i=0;i<boxes.length;i++){boxes[i].checked=true;}});</script>';
}

if (function_exists('add_hook') && defined('ROOTDIR')) {
    add_hook('ClientAreaFooterOutput', 1, function ($vars) {
        return pmLoginRememberMeDefaultScript();
    });
}
