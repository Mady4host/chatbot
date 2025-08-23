"""markdown
# Instagram Bot Spec (scaffold)

This document maps existing Messenger features to Instagram and documents constraints.

## Features mapping
- Receiving DMs: supported via Instagram Messaging API (instagram_manage_messages)
- Sending messages: supported but subject to 24-hour window rules and template restrictions
- Subscribers: store IG user ids and basic metadata
- Broadcast / sequence / drip: implement via campaign runner with window checks and templates
- Widgets / deep links: use IG deep links and QR codes instead of Messenger customer chat

## 24-hour window
- Messages to users are allowed freely within 24 hours after the user's last message.
- Outside 24 hours, only allowed message types or templates may be sent (app review may be required).

## Webhooks
- Verify using hub.challenge GET flow and secure POST events with X-Hub-Signature-256

## App Review and permissions
- instagram_basic, instagram_manage_messages, pages_messaging, pages_manage_metadata may require review.

## Next implementation tasks
- Implement OAuth token exchange and persist tokens securely
- Implement Ig_rx_login->sendMessage to call actual IG APIs and handle responses
- Build campaign runner (workers, retry, rate-limiting)
- Generate widgets (deep link JS, zip) and admin UI

"""
