import datetime
from types import SimpleNamespace
import importlib.util
import pathlib

import pytest


spec = importlib.util.spec_from_file_location(
    "get_stocks",
    pathlib.Path(__file__).with_name("get_stocks.py")
)
gs = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gs)


def test_start_end_arguments_limit_range(monkeypatch, tmp_path):
    recorded = {}

    def fake_fetch_one(ticker, start_date, end_date, **kwargs):
        recorded['start'] = start_date
        recorded['end'] = end_date
        raise RuntimeError('stop')

    monkeypatch.setattr(gs, 'fetch_one', fake_fetch_one)

    class DummyDataFrame:
        def __init__(self, data=None):
            self.data = data
        def to_csv(self, path, index=False):
            pass

    dummy_pd = SimpleNamespace(DataFrame=DummyDataFrame)

    def fake_require(pkg):
        return dummy_pd

    monkeypatch.setattr(gs, 'require', fake_require)
    monkeypatch.setattr(gs.os.path, 'abspath', lambda p: str(tmp_path))

    gs.main(['AAA', '--start', '2020-01-01', '--end', '2020-01-31'])

    assert recorded['start'] == datetime.date(2020, 1, 1)
    assert recorded['end'] == datetime.date(2020, 1, 31)
