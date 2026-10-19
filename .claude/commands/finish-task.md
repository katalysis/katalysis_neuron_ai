Finish the current delivery plan task.

1. Run the checks that exist so far (PHPUnit, `neuron:eval` scenarios) and report the results honestly. Include failures, and compare them with the last recorded baseline.
2. Show `git diff --stat development...HEAD`. Confirm that no files outside the approved plan were changed and that nothing was deleted without being in the plan.
3. Add an entry to `documentation/PROGRESS.md` using the template there.
4. If the work had to differ from `DELIVERY_PLAN.md` or a design doc, say so clearly. Do not edit the plan or design docs yourself; list the proposed change under "Plan changes proposed".
5. Commit with a message starting with the task ID. Then STOP and ask before pushing or opening a PR, filling in the PR using `.github/pull_request_template.md`.
