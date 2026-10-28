#!/usr/bin/env python3
"""Compatibility entry point: the v0.0.4 fault probes are now positive regressions.

The original fault-confirmation version remains in commit 886a71f. This
entry runs the current regression gate and exits nonzero on a regression.
"""
from pathlib import Path
import runpy
import sys

root = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(root / 'tests'))
runpy.run_path(str(root / 'tests/portability-regressions.py'), run_name='__main__')
