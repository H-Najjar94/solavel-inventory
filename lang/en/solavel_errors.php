<?php

// Unified branded error pages (resources/views/errors/_branded*.blade.php).
return [
    'app_name' => 'SolaStock',
    'app_subtitle' => 'Inventory',

    'link' => ['back' => 'Back to previous page', 'home' => 'Back to dashboard'],
    'report' => ['prompt' => 'Still stuck?', 'link' => 'Report a problem'],
    'info' => [
        'what_happened' => 'What happened',
        'permission' => 'Permission:',
        'feature' => 'Feature:',
        'organization' => 'Organization:',
        'app' => 'App:',
        'details' => 'Details',
    ],

    'chip' => [
        '401' => 'Sign in', '403' => 'Permission', 'plan' => 'Plan', 'app' => 'Subscription', 'org' => 'Organization',
        'setup' => 'Setup', 'membership' => 'Membership', 'email' => 'Email', 'sso' => 'Sign in',
        '404' => 'Not found', '405' => 'Action', '413' => 'File size', '419' => 'Session', '429' => 'Too many requests',
        '500' => 'Server error', '502' => 'Slow response', '504' => 'Slow response', '503' => 'Maintenance', 'access_check' => 'Access check',
    ],

    'e401' => ['title' => 'Please sign in first', 'line' => 'Sign in to pick up where you left off.'],
    'role' => [
        'title' => "This page isn't part of your role",
        'line' => 'Your role doesn\'t include ":permission". Your organization admin can add it for you.',
        'line_generic' => "Your role doesn't include this page. Your organization admin can add it for you.",
    ],
    'plan' => ['title' => "This feature isn't in your plan", 'line' => '":feature" comes with a higher plan. Upgrade to turn it on right away.', 'line_generic' => 'This feature comes with a higher plan. Upgrade to turn it on right away.'],
    'noapp' => ['title' => ":app isn't active for your organization yet", 'line' => 'Subscribe to start using it with your team.'],
    'org' => ['title' => "You're not a member of this organization", 'line' => 'Switch to an organization you belong to, or ask for an invitation.'],
    'setup' => ['title' => ":app isn't set up yet", 'line' => 'Setup isn\'t finished. Complete it now, or ask your organization owner to finish it.'],
    'membership' => ['title' => 'Your membership is paused', 'line' => 'Contact your organization owner to turn it back on.'],
    'email' => ['title' => 'Confirm your email', 'line' => 'We sent you a confirmation link. Open it, then come back here.'],
    'sso' => ['title' => "We couldn't finish signing you in", 'line' => 'Your sign-in link has expired. Please try again.'],
    'e404' => ['title' => "We couldn't find this page", 'line' => 'It may have moved or been deleted, or the link has a small typo.'],
    'e405' => ['title' => "This action doesn't work this way", 'line' => 'Go back and try again using its button.'],
    'e413' => ['title' => 'That file is too large', 'line' => 'Try a smaller file, or split it into parts.'],
    'e419' => ['title' => 'Your session has expired', 'line' => 'It was idle for a while, so we paused it to keep you safe. Refresh the page and carry on.'],
    'e429' => ['title' => 'Hold on a moment', 'line' => 'We got a lot of requests very quickly. Wait a minute, then try again.'],
    'e500' => ['title' => 'Something went wrong on our side', 'line' => "It's not your fault, and our team has been notified automatically."],
    'e502' => ['title' => 'The service is taking longer than usual', 'line' => 'Please try again in a few moments.'],
    'e503' => ['title' => "We're making some quick improvements", 'line' => "We'll be back in a few minutes. Your data is safe."],
    'access_check' => ['title' => "We couldn't check your access right now", 'line' => 'Please try again in a few moments.'],

    'action' => [
        'sign_in' => 'Sign in', 'sign_in_sub' => 'Continue with your Solavel account',
        'portal' => 'Open Solavel portal', 'portal_sub' => 'Your apps and organizations',
        'switch_org' => 'Switch organization', 'switch_org_sub' => 'Pick an organization you belong to',
        'upgrade' => 'Upgrade plan', 'upgrade_sub' => 'See plans that include this feature',
        'subscribe' => 'Subscribe to :app', 'subscribe_sub' => 'Turn it on for your organization',
        'finish_setup' => 'Finish setup', 'finish_setup_sub' => 'Pick up the setup where it stopped',
        'verify_email' => 'Verify email', 'verify_email_sub' => 'Resend or open the confirmation link',
        'retry' => 'Try again', 'retry_sub' => 'Reload this page',
        'refresh' => 'Refresh page', 'refresh_sub' => 'Get a fresh session and carry on',
        'go_back' => 'Go back', 'go_back_sub' => 'Return to the previous page',
        'dashboard' => 'Go to dashboard', 'dashboard_sub' => 'Start again from the main page',
    ],
];
