---
layout: home
title: Dynamic Throttle for Laravel
titleTemplate: false

hero:
  name: Dynamic Throttle
  text: Rate limits you change while your Laravel app runs
  tagline: Per IP, user, API token, header or tenant, with dry-run, exemptions, emergency switches and a dashboard.
  actions:
    - theme: brand
      text: Get started
      link: /getting-started
    - theme: alt
      text: Read the guide
      link: /guide/
    - theme: alt
      text: View on GitHub
      link: https://github.com/hanzala-afzal-dev/laravel-dynamic-throttle

features:
  - title: Change a limit in seconds
    details: Rules live in your database. Edit one in the dashboard, with Artisan or in code, and every server applies it within two seconds, with no deploy.
  - title: Try it before it blocks
    details: New rules start in dry-run. They count requests and show who they would block, and block no one until you promote them.
  - title: See who is blocked
    details: A live feed of blocks, each rule's top offenders and its traffic per hour. Explain says why any request would be limited.
  - title: Limit by who the client is
    details: Per IP, user, API token or header, or your own scope such as a tenant or a plan, with exemptions that end by themselves.
  - title: Ready for an incident
    details: Lift or scale every limit for a set time, or switch one rule off. The runbook covers Redis down, an evicted manifest and a bad rule.
  - title: Safe when storage fails
    details: When Redis is down, requests go through, or get a 503 on the rules you choose. No request ever reads the rules from the database.
---

<!-- The documentation site's home page (ADR-0029). The text below the header comes from the README. -->

<script setup>
import limitChange from '@art/limit-change.gif';
</script>

<img :src="limitChange" width="1050" height="560" alt="A limit changing while the app runs. On the left, a terminal loops curl against /api/orders, and every response is 200. On the right, the dashboard edits the Orders API rule: its limit goes from 100 to 2 requests a minute, and it is saved. Within two seconds, the terminal's responses turn to 429 Too Many Requests, with no deploy." />


<!--@include: ../../README.md#why-->
