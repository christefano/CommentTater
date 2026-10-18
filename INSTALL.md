# Installation

## Requirements

- Kanboard 1.2.54 or later. The comment form is a copy of 1.2.54's.
- Nothing else is needed.
- No tables and no database changes.


## Install

1. Put the `CommentTater` folder into `/path/to/kanboard/plugins/`.
2. Reload PHP-FPM if OPcache is on and a changed file doesn't show up, since OPcache can serve the old copy for a few minutes. A first install has no old copy, so it loads right away.
3. Open any task, click *Add a comment*, and check that *Update task* shows under the text box. A user who can comment but can't edit the task sees the block open with a note and no fields.
4. Open *Settings -> CommentTater* to pick the fields, the icon, and where the list of changes goes.


## Update

Copy the new `CommentTater` folder over the old one. The saved settings live in Kanboard's settings table, so they survive plugin updates.

After a Kanboard upgrade, compare `plugins/CommentTater/Template/comment/create.php`, `task_comments/create.php` and `comment/show.php` with Kanboard's `app/Template/comment/create.php`, `task_comments/create.php` and `comment/show.php`. The plugin's two `create.php` files are copies of core's forms (the popup and the one on the task page) with the *Update task* block added, and its `show.php` is a copy of core's comment with the change list striped, so a change in core's files doesn't show up in the copies until you copy it over.


## Remove

Delete `plugins/CommentTater` and Kanboard's own comment form comes back. There are no tables to drop. The `commenttater_` settings stay in the settings table in case CommentTater is later reinstalled. To remove them too, run this against Kanboard's database after deleting the plugin folder:

```sql
DELETE FROM settings WHERE `option` LIKE 'commenttater%';
```

On PostgreSQL (where backticks don't work) use double quotes:

```sql
DELETE FROM settings WHERE "option" LIKE 'commenttater%';
```

If Kanboard has a queue worker, let the queue empty before you delete the plugin folder. Queued notification jobs name a class from the plugin, and the worker fails on a job whose class is gone. Nothing needs to be done if Kanboard's default inline queue has nothing queued.

Comments already saved keep their task update lists, since those are ordinary comment text.


### Remove the task update lists in existing comments

Existing comments keep their task update lists per comment, so the task fields stay as they are and only the comment text changes. You review the matches first and then change only the comments you pick by `id`. These statements can't be undone, so make a database backup before you start.

A list line looks like `- Priority: 1 → 3 (+2)`. A match has dash, one of CommentTater's eleven field labels, a colon, and ` → ` (in that order). The labels are saved in the user's language, and the statements below use the English ones. A comment saved in another language doesn't match until you add its labels to the list, so it is left alone and never deleted by mistake.

**1. See what matches (MariaDB and MySQL 8).** The first query lists comments that hold only a list. The second lists comments that have a list and other text. Read both and note the ids you want to change.

```sql
SELECT id, task_id, `comment` FROM comments
WHERE `comment` REGEXP '^(- (Color|Assignee|Category|Column|Priority|Due Date|Start Date|Original estimate|Time spent|Complexity|Reference): [^\n]* → [^\n]*(\n|$))+$';

SELECT id, task_id, `comment` FROM comments
WHERE `comment` REGEXP '(^|\n)- (Color|Assignee|Category|Column|Priority|Due Date|Start Date|Original estimate|Time spent|Complexity|Reference): [^\n]* → [^\n]*'
AND `comment` NOT REGEXP '^(- (Color|Assignee|Category|Column|Priority|Due Date|Start Date|Original estimate|Time spent|Complexity|Reference): [^\n]* → [^\n]*(\n|$))+$';
```

**2. Strip the list from the comments that have other text.** Put the ids from the second query in the parentheses.

```sql
UPDATE comments
SET `comment` = TRIM(BOTH '\n' FROM REGEXP_REPLACE(`comment`, '(^|\n)- (Color|Assignee|Category|Column|Priority|Due Date|Start Date|Original estimate|Time spent|Complexity|Reference): [^\n]* → [^\n]*', ''))
WHERE id IN (12, 15);
```

**3. Delete the comments that held only a list, if you want them gone.** Put the ids from the first query in the parentheses. Skip this step to keep them.

```sql
DELETE FROM comments WHERE id IN (13, 14);
```

PostgreSQL uses `"comment"` and `regexp_replace(..., 'g')`. This statement is untested:

```sql
UPDATE comments
SET "comment" = btrim(regexp_replace("comment", E'(^|\n)- (Color|Assignee|Category|Column|Priority|Due Date|Start Date|Original estimate|Time spent|Complexity|Reference): [^\n]* → [^\n]*', '', 'g'), E'\n')
WHERE id IN (12, 15);
```

SQLite doesn't have regular expressions, so use Python 3's built-in `sqlite3` module (nothing to install) from the Kanboard folder. Run it as is to list the matches. Then put the IDs you checked in `CHANGE`, run it again, and it will strip the list from those comments and deletes the ones that are empty.

```bash
python3 -I - <<'EOF'
import re, sqlite3
CHANGE = []   # ids to change, for example [12, 13, 15]
labels = 'Color|Assignee|Category|Column|Priority|Due Date|Start Date|Original estimate|Time spent|Complexity|Reference'
line = r'(^|\n)- (' + labels + r'): [^\n]* → [^\n]*'
pattern = re.compile(line)
db = sqlite3.connect('data/db.sqlite')
for id, task_id, text in db.execute('SELECT id, task_id, comment FROM comments').fetchall():
    if not pattern.search(text):
        continue
    new = pattern.sub('', text).strip('\n')
    print(id, task_id, 'list only' if new == '' else 'list and text', repr(text[:60]))
    if id not in CHANGE:
        continue
    if new == '':
        db.execute('DELETE FROM comments WHERE id = ?', (id,))
    else:
        db.execute('UPDATE comments SET comment = ? WHERE id = ?', (new, id))
db.commit()
EOF
```

Kanboard's activity stream may keep its own copy of a comment's text. These statements don't change it.


## Conflicts

- CommentTater overrides Kanboard core's `comment/create`, `task_comments/create` and `comment/show` templates. It does that in `onStartup()` after other plugins have loaded. Another plugin that overrides the same template can conflict with it, and the plugin that sets its override last takes priority. Kanboard doesn't guarantee the load order.
- CommentTater's settings page uses a controller named `CommentTaterSettingsController`. Kanboard's access rules go by the controller's name and a plain `SettingsController` could clash with another plugin's.
