Start delivery plan task $ARGUMENTS.

1. Read `CLAUDE.md`, the task $ARGUMENTS in `documentation/DELIVERY_PLAN.md`, any design doc it refers to (e.g. `documentation/EXTENSIONS.md`), and the latest entries in `documentation/PROGRESS.md`.
2. Check you are on `development` with a clean working tree, then create a branch `task/$ARGUMENTS-<short-slug>`.
3. Write a short plan and STOP for approval. The plan must cover:
   - **Goal:** one sentence
   - **Files:** each one you will change or create
   - **Out of scope:** what you will deliberately not touch
   - **Tests:** how you will check it works (PHPUnit, scenarios, manual steps)
   - **Risks and questions:** anything unclear in the plan
4. Do not edit any files until the plan is approved.
