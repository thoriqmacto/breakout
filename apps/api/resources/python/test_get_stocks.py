import datetime
from types import SimpleNamespace
import importlib.util
import pathlib

import json

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


def test_check_latest_outputs_status(monkeypatch, capsys):
    gs.pd = object()
    gs.yf = object()

    monkeypatch.setattr(gs, 'build_latest_status', lambda symbol, compare_date: {
        'symbol': symbol,
        'latest_date': '2024-05-10',
        'requested_date': compare_date.isoformat() if compare_date else None,
        'is_available': True,
        'checked_at': '2024-05-10T00:00:00Z',
    })

    gs.main(['--check-latest', '--latest-date', '2024-05-10'])

    out = capsys.readouterr().out.strip()
    payload = json.loads(out)
    assert payload['symbol'] == '^JKSE'
    assert payload['is_available'] is True

    gs.pd = None
    gs.yf = None


def test_emit_dates_uses_close_column_from_multiindex(monkeypatch, capsys):
    class DummySeries(list):
        def tolist(self):
            return list(self)

    class DummyDataFrame:
        def __init__(self):
            self.columns = ['Date', 'Close_^JKSE']
            self._data = {
                'Date': ['2024-01-02', '2024-01-03'],
                'Close_^JKSE': [123.45, None],
            }

        def __contains__(self, item):
            return item in self._data

        def __getitem__(self, item):
            return DummySeries(self._data[item])

    dummy_pd = SimpleNamespace(
        isna=lambda value: value is None,
        to_datetime=lambda value: datetime.datetime.fromisoformat(str(value))
    )

    gs.pd = dummy_pd
    gs.yf = object()

    monkeypatch.setattr(gs, 'require', lambda pkg: dummy_pd)
    monkeypatch.setattr(gs, 'fetch_one', lambda *args, **kwargs: DummyDataFrame())

    gs.main(['^JKSE', '--emit-dates', '--start', '2024-01-01', '--end', '2024-01-31'])

    payload = json.loads(capsys.readouterr().out.strip())

    assert payload['tickers'][0]['entries'] == [
        {'date': '2024-01-02', 'close': 123.45},
        {'date': '2024-01-03', 'close': None},
    ]

    gs.pd = None
    gs.yf = None
