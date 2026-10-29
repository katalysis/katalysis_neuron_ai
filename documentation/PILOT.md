# Neuron AI: internal pilot guide

For Glyn, installing Neuron on a Katalysis site from **30 October 2026**. The pilot is for Katalysis's own sites only. Client use waits for the Phase 3 exit.

## 1. Before you install

**Check the site:**
- **PHP 8.1 or newer**, with **`ext-curl`**. Run `php -v` and `php -m | grep curl` on the server, as the web server's PHP.
- **Concrete 9.3 or newer.** The package refuses older versions. The dev site runs 9.5.5, so 9.5.x is what's been tested.
- **Not before 30 October 2026.** That's when the Neuron library the package bundles (4.1.12) is two weeks old.
- **The trash can is on:** `concrete/bin/concrete c5:config get concrete.misc.enable_trash_can` must print `true`. Without it the assistant refuses to delete pages, because it never deletes permanently.

**Back up:**
- **Database:** a full dump (`mysqldump`).
- **Files:** `application/files` and `application/config`, and the `packages/` folder if it already holds other packages.
- Keep both until the pilot on that site ends.

## 2. Install

1. Copy the `katalysis_neuron_ai` folder into the site's `packages/` folder. It includes its `vendor/` folder, so there's no `composer install` to run.
2. Install it from **Dashboard → Extend Concrete**, or run `concrete/bin/concrete c5:package:install katalysis_neuron_ai`.
3. Clear the cache: **Dashboard → System & Settings → Optimization → Clear Cache**.

**Settings** are under **Dashboard → System & Settings → AI → Neuron AI**. Only the super user can open the page at first (the "Configure Neuron AI" permission).
- **OpenAI API key:** enter it here. It's stored in Concrete's config and never shown to the browser or the model. Don't put it in a committed file.
- **Model:** `gpt-4o`, the default and the only model the scenarios have run on.

**Two settings with no page.** Set them only to change the defaults:

| Setting | Default | What it does | To change it |
|---|---|---|---|
| `katalysis.ai.provider_timeout` | 90 seconds | How long a request to OpenAI may take before the turn fails with "The AI service didn't answer in time" | `concrete/bin/concrete c5:config set katalysis.ai.provider_timeout 90` |
| `katalysis.ai.approval.block_limit` | 10 | Above this many blocks, an approval card warns that the change is large | `concrete/bin/concrete c5:config set katalysis.ai.approval.block_limit 10` |

## 3. Who gets which permission

The keys are under **Dashboard → System & Settings → Permissions & Access → Tasks**.

| Key | Who gets it in the pilot | What it allows |
|---|---|---|
| **Use Neuron AI assistant** (`use_neuron_ai`): Tier 1, Content | The pilot editors (a group of them) | The chat panel, and reading and editing content: pages, blocks, attributes, files, topics. All within the user's own Concrete permissions. |
| **Neuron AI tier 2: Configure** (`neuron_ai_tier_configure`) | Administrators only. They have it by default; leave it there. | Also defining the site's structure: new attributes, attribute sets, topic trees. **These take effect immediately; there is no draft.** Each still waits for an approval card, and the card says it takes effect at once. |
| Neuron AI tier 3: Generate | Nobody | Not used in the pilot. |
| Configure Neuron AI | Super user only | The settings page. |

The assistant never does more than the person could do in Concrete. If an editor can't edit a page, a stack or a file, the assistant can't either, and it says which permission is missing.

## 4. What the assistant does: for the team

- **It works in drafts.** Every change to a page goes into a new, unpublished version. You see it on the page in edit mode, and it goes live only when **you** publish it.
- **It asks first.** Before any change, a card appears in the chat that says exactly what will change. Nothing happens until you click **Approve**. **Deny** means nothing changes.
- **Global areas (headers, footers, shared banners) affect every page.** The card says "it appears on every page that shows it" and how many pages that is. The change goes into an unpublished version of the shared area:
  - **If you can approve that area:** publishing the page publishes it too.
  - **If you can't:** publishing the page won't publish it; someone who can approves it under **Dashboard → Stacks & Global Areas**. The card says which applies to you.
- **It never publishes anything.**
- **It never deletes anything permanently:**
  - Pages it deletes go to the trash, and can be restored from **Dashboard → Sitemap → Trash**.
  - Files it is asked to delete go to a folder called **"Assistant – to delete"** for a person to review.
- **Moved pages keep their old addresses as redirects.** The reply says how to move them back.
- **Read the "Changed:" line under each reply.** It lists what actually changed in that turn, from the tools themselves, not from the model. If the reply and the Changed line disagree, **the Changed line is right**.

## 5. Reporting a problem

Every report becomes a test scenario, so detail matters.

**Copy:**
1. **The whole chat:** select it in the panel and paste it, including the approval cards and the Changed lines.
2. **The page:** its address, and whether you were in edit mode.
3. **The time,** to the minute, with the time zone.
4. **What you expected,** in one sentence.
5. **If something went wrong on the server:** the matching lines from **Dashboard → Reports → Logs**. Search for "Katalysis Neuron AI".

**Where it goes:** open an issue in the `katalysis_neuron_ai` repository with the label `pilot`. Glyn or Alex turns it into a scenario and, if needed, a fix task.

**If a change is wrong:**
- For a page or a global area, discard the unpublished version (Page Versions, or Stacks & Global Areas).
- For a moved page, move it back.
- For a deleted page, restore it from the trash.
- Then report it.

## 6. Known open items

- **After you click Deny, the model may try the same thing again** with slightly different details, so a second card appears. Deny it again, or tell it to stop. A fix is planned (P3.9-3): after a Deny, the same tool is refused for the rest of that turn.
- **Chats are kept indefinitely** until retention and an admin purge arrive (P3.9-2). Don't paste personal data into the chat that you wouldn't keep in the CMS.
- **The model sometimes says it did something that was refused.** The Changed line is the truth. Report it when it happens.
- **Headers and footers now appear in everything the assistant reads,** so it may suggest changes to them. Each suggestion has its own card.
- **The page count on a global-area card is a floor.** Pages nobody has opened since the area was added aren't counted.

## 7. Rolling back

**Quickest full rollback:** restore the database and files backups from section 1.

**To remove the package and keep the site as it is:**
1. **Dashboard → Extend Concrete → Neuron AI → Uninstall** (optionally "delete files").
2. **This removes:** the permission keys, the settings page and the chat panel.
3. **It leaves behind:**

   | What | What to do with it |
   |---|---|
   | The tables `KatalysisNeuronAiChats`, `KatalysisNeuronAiChatMessages`, `KatalysisNeuronAiApprovals` and `KatalysisNeuronAiWorkflowStore` (chats, approval records, paused runs) | Concrete doesn't drop a package's tables. Drop them by hand if you want the chats gone. |
   | The `katalysis.ai.*` settings, **including the API key** | Clear the key first, or remove it with `c5:config set katalysis.ai.open_ai_key ''`. |
   | Unpublished page versions and stack versions the assistant made | Discard them, or leave them; they aren't live. |
   | Pages it sent to the trash | Restore them from the trash, or leave them. |
   | Files in "Assistant – to delete" | Review them as usual. |
