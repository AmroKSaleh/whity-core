#!/usr/bin/env python3
"""Report what a mutation-testing run actually verified — not merely that it finished.

WC-877.

The problem this exists to solve
--------------------------------
`mutation-testing.yml` skips the Infection job when `main` has not moved. When a
job is skipped, GitHub reports the RUN conclusion as `success`: the check is
green, the API says `"conclusion": "success"`, and nothing at run level
distinguishes "the mutation suite passed" from "the mutation suite did not run".

That is not a theoretical gap. On this repository, run 32107507319 executed
Infection against 896b0962 and FAILED; the next nine scheduled runs, on the
SAME commit, skipped the job and reported `success`. The workflow history read
as an unbroken green streak over a tree whose mutation suite was broken the
whole time, and reading it that way cost an investigation eleven days and
pointed it at the wrong commit.

The fix is not to make the skip louder. It is to make GREEN MEAN SOMETHING:

    A run is green only if the CURRENT tip of main has actually been
    mutation-tested, and passed.

So when the job is skipped, this script does not shrug — it goes and finds the
most recent run in which Infection genuinely executed, and reports THAT verdict
as this run's verdict:

    executed, passed        -> green
    executed, failed        -> RED, naming the run that failed and when
    skipped, last real run passed on this same commit
                            -> green, and the summary says so explicitly
    skipped, last real run FAILED
                            -> RED, for as long as it stays broken
    skipped, main has moved since the last real execution
                            -> RED: this tree has never been mutation-tested
                               (a dropped scheduled run walks the 6h30m window
                               past a commit, and nothing else notices)
    skipped, no real execution found in the lookback
                            -> RED: the gate is stale, which is the general
                               case of "a gate that never runs is
                               indistinguishable from one that always passes"

Usage
-----
    scripts/ci-mutation-gate.py --repo OWNER/NAME --run-id 12345 \
        [--workflow mutation-testing.yml] \
        [--job "Infection mutation testing (Auth/Tenant/Delegation)"] \
        [--lookback 40] [--summary "$GITHUB_STEP_SUMMARY"]

`--this-result` and `--head-sha` are read from the API when omitted, so the same
invocation works locally against any historical run id. That is deliberate: this
gate is checkable against real history rather than only observable in CI, and a
gate nobody has watched fail is not known to work.

Exit 0 = the current tip of main has passed mutation testing. Exit 1 = it has
not, or that cannot be established.
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import time
from datetime import datetime, timezone

DEFAULT_WORKFLOW = "mutation-testing.yml"
DEFAULT_JOB = "Infection mutation testing (Auth/Tenant/Delegation)"

# Conclusions that mean the job genuinely ran. `cancelled` is included on
# purpose: a cancelled run verified nothing, so it must not be mistaken for a
# window in which the suite executed.
EXECUTED = ("success", "failure", "timed_out")
PASSED = ("success",)


class ApiError(RuntimeError):
    pass


def gh_api(path: str, retries: int = 3) -> object:
    """Call the GitHub API through `gh`, retrying a transient failure.

    A gate that cannot read the history must NOT report green — an unverifiable
    claim is the whole failure mode being fixed here — so exhausting the retries
    raises rather than degrading to "probably fine".
    """
    last = ""
    for attempt in range(1, retries + 1):
        proc = subprocess.run(
            ["gh", "api", "-H", "Accept: application/vnd.github+json", path],
            capture_output=True,
            text=True,
        )
        if proc.returncode == 0:
            return json.loads(proc.stdout)
        last = (proc.stderr or proc.stdout).strip()
        if attempt < retries:
            time.sleep(3 * attempt)
    raise ApiError(f"gh api {path} failed after {retries} attempts: {last}")


def job_conclusion(repo: str, run_id: int, job_name: str) -> str | None:
    """The named job's conclusion within one run, or None if it is not present."""
    payload = gh_api(f"repos/{repo}/actions/runs/{run_id}/jobs?per_page=100")
    assert isinstance(payload, dict)
    for job in payload.get("jobs", []):
        if job.get("name") == job_name:
            return job.get("conclusion")
    return None


def short(sha: str | None) -> str:
    return (sha or "unknown")[:8]


def human_age(iso: str | None) -> str:
    if not iso:
        return "unknown"
    try:
        then = datetime.fromisoformat(iso.replace("Z", "+00:00"))
    except ValueError:
        return iso
    hours = (datetime.now(timezone.utc) - then).total_seconds() / 3600
    if hours < 48:
        return f"{hours:.0f}h ago"
    return f"{hours / 24:.1f}d ago"


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--repo", required=True, help="OWNER/NAME")
    ap.add_argument("--run-id", required=True, type=int)
    ap.add_argument("--workflow", default=DEFAULT_WORKFLOW)
    ap.add_argument("--job", default=DEFAULT_JOB)
    ap.add_argument(
        "--this-result",
        default=None,
        help="conclusion of the Infection job in THIS run; read from the API when omitted",
    )
    ap.add_argument("--head-sha", default=None, help="read from the API when omitted")
    ap.add_argument(
        "--lookback",
        type=int,
        default=40,
        help="how many previous runs to search for a real execution (~10 days at 4/day)",
    )
    ap.add_argument("--summary", default=os.environ.get("GITHUB_STEP_SUMMARY"))
    args = ap.parse_args()

    lines: list[str] = []
    annotations: list[tuple[str, str]] = []

    def say(line: str) -> None:
        print(line)
        lines.append(line)

    def annotate(level: str, message: str) -> None:
        annotations.append((level, message))

    try:
        this_result = args.this_result
        head_sha = args.head_sha

        # Always fetch the run: its created_at bounds the history search below,
        # so that replaying a historical run id locally sees the same past this
        # run saw in CI rather than everything that has happened since.
        this_run = gh_api(f"repos/{args.repo}/actions/runs/{args.run_id}")
        assert isinstance(this_run, dict)
        started_at = this_run.get("created_at") or ""
        head_sha = head_sha or this_run.get("head_sha")
        if this_result is None:
            this_result = job_conclusion(args.repo, args.run_id, args.job) or "absent"

        say(f"run {args.run_id} @ {short(head_sha)} — Infection job: {this_result}")

        # ---- the job actually ran in this window ---------------------------
        if this_result in PASSED:
            verdict = f"Infection EXECUTED in this run and passed on {short(head_sha)}."
            say(verdict)
            annotate("notice", verdict)
            ok = True

        elif this_result in EXECUTED:
            verdict = f"Infection EXECUTED in this run and did not pass ({this_result})."
            say(verdict)
            annotate("error", verdict)
            ok = False

        # ---- it did not: inherit the last real verdict ----------------------
        else:
            say(
                f"Infection did NOT run in this window ({this_result}). "
                "A run that verified nothing cannot report green on its own account, "
                "so the last window that DID execute decides this run's verdict."
            )

            runs = gh_api(
                f"repos/{args.repo}/actions/workflows/{args.workflow}/runs"
                f"?per_page={args.lookback}"
            )
            assert isinstance(runs, dict)

            previous = None
            for candidate in runs.get("workflow_runs", []):
                if candidate.get("id") == args.run_id:
                    continue
                # Strictly the PAST. In CI this run is the newest one and the
                # filter is a no-op; replaying an old run id locally, it is what
                # keeps the answer honest instead of leaking hindsight in.
                if started_at and (candidate.get("created_at") or "") >= started_at:
                    continue
                conclusion = job_conclusion(args.repo, candidate["id"], args.job)
                if conclusion in EXECUTED:
                    previous = (candidate, conclusion)
                    break

            if previous is None:
                verdict = (
                    f"STALE: Infection has not actually executed in the last "
                    f"{args.lookback} runs of {args.workflow}. A gate that never runs is "
                    "indistinguishable from one that always passes, so this is a failure, "
                    "not a pass."
                )
                say(verdict)
                annotate("error", verdict)
                ok = False
            else:
                run, conclusion = previous
                where = (
                    f"run {run['id']} @ {short(run.get('head_sha'))} "
                    f"({human_age(run.get('created_at'))})"
                )
                if conclusion not in PASSED:
                    verdict = (
                        f"The last window in which Infection actually executed FAILED: "
                        f"{where}, conclusion {conclusion}. This run skipped the suite, so "
                        "it has verified nothing and inherits that red rather than papering "
                        "over it with a skip."
                    )
                    say(verdict)
                    annotate("error", verdict)
                    ok = False
                elif run.get("head_sha") != head_sha:
                    verdict = (
                        f"STALE: main is at {short(head_sha)} but the last real execution was "
                        f"on {short(run.get('head_sha'))} ({where}), and this window skipped. "
                        "The current tip has never been mutation-tested — a scheduled run was "
                        "dropped, or a commit fell outside the 6h30m window."
                    )
                    say(verdict)
                    annotate("error", verdict)
                    ok = False
                else:
                    verdict = (
                        f"SKIPPED — main has not moved. Reporting the last real execution: "
                        f"{where} passed on this same commit."
                    )
                    say(verdict)
                    annotate("notice", verdict)
                    ok = True

    except ApiError as exc:
        say(f"Could not establish what this run verified: {exc}")
        say(
            "Failing deliberately. An unverifiable green is exactly the signal this gate "
            "exists to remove."
        )
        annotate("error", f"mutation gate could not read the run history: {exc}")
        ok = False

    if args.summary:
        with open(args.summary, "a", encoding="utf-8") as fh:
            fh.write("## Mutation testing\n\n")
            fh.write(("PASS" if ok else "FAIL") + "\n\n")
            for line in lines:
                fh.write(f"- {line}\n")
            fh.write("\n")

    for level, message in annotations:
        print(f"::{level}::{message}")

    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
