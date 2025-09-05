# Fetch 15y of daily OHLCV for IDX tickers and save ONE CSV per ticker.
# Also saves a Summary.csv with metadata.

import sys, subprocess, importlib, datetime, os, time, traceback

# ---------- Helpers for package installs ----------
def console_python():
    exe = sys.executable
    if exe.lower().endswith("pythonw.exe"):
        alt = exe[:-1]
        if os.path.exists(alt):
            return alt
    return exe

def safe_import(pkg):
    try:
        return importlib.import_module(pkg)
    except ImportError:
        return None

def install_package(pkg):
    pyexe = console_python()
    try:
        subprocess.check_call([pyexe, "-m", "ensurepip", "--upgrade"])
    except Exception:
        pass
    try:
        subprocess.check_call([pyexe, "-m", "pip", "install", "--upgrade", "pip", "setuptools", "wheel"])
    except Exception:
        print(f"[WARN] Could not upgrade pip")
    subprocess.check_call([pyexe, "-m", "pip", "install", pkg])

def require(pkg):
    mod = safe_import(pkg)
    if mod is None:
        print(f"[INFO] Installing {pkg} ...")
        install_package(pkg)
        mod = importlib.import_module(pkg)
    return mod

pd = require("pandas")
yf = require("yfinance")

# ---------- Utils ----------
def start_15y_ago(today: datetime.date) -> datetime.date:
    try:
        return today.replace(year=today.year - 15)
    except ValueError:
        return today.replace(month=2, day=28, year=today.year - 15)

def flatten_columns(df):
    if isinstance(df.columns, pd.MultiIndex):
        df.columns = ["_".join([str(x) for x in tup if str(x) != ""])
                      for tup in df.columns.to_list()]
    return df

def read_tickers(cli_args):
    if len(cli_args) > 1:
        return [t.strip() for t in cli_args[1:] if t.strip()]
    fname = os.path.join(os.path.dirname(os.path.abspath(__file__)), "tickers.txt")
    if os.path.exists(fname):
        with open(fname, "r", encoding="utf-8") as f:
            lines = [ln.strip() for ln in f.readlines()]
            return [ln for ln in lines if ln and not ln.startswith("#")]
    print("[WARN] No tickers provided; using example list.")
    return ["ANTM.JK", "ISAT.JK", "BRIS.JK", "AKRA.JK", "TLKM.JK", "PTBA.JK",
            "BBRI.JK", "BBCA.JK", "ASII.JK", "ICBP.JK", "UNVR.JK", "PGAS.JK"]

# ---------- Downloader ----------
def fetch_one(ticker, start_date, end_date, pause_sec=0.8, max_retries=3):
    last_err = None
    for attempt in range(1, max_retries+1):
        try:
            print(f"[INFO] ({attempt}/{max_retries}) Downloading {ticker} ...")
            df = yf.download(
                ticker,
                start=start_date.isoformat(),
                end=(end_date + datetime.timedelta(days=1)).isoformat(),
                interval="1d",
                auto_adjust=False,
                progress=False,
                group_by="column",
            )
            if df is not None and not df.empty:
                df = flatten_columns(df)
                df.index.name = "Date"
                df.reset_index(inplace=True)
                return df
            else:
                last_err = RuntimeError(f"No data returned for {ticker}.")
        except Exception as e:
            last_err = e
            print(f"[WARN] {ticker} failed: {e}")
            traceback.print_exc()
        time.sleep(pause_sec)
    raise last_err

# ---------- Main ----------
def main():
    tickers = read_tickers(sys.argv)
    today = datetime.date.today()
    start_date = start_15y_ago(today)
    end_date = today

    outdir = os.path.abspath("resources/python/csv/")
    os.makedirs(outdir, exist_ok=True)

    rows = []
    print(f"[INFO] Fetching {len(tickers)} tickers from {start_date} to {end_date}")

    for t in tickers:
        try:
            df = fetch_one(t, start_date, end_date)
            csv_path = os.path.join(outdir, f"{t.replace('.JK','_PY')}.csv")
            df.to_csv(csv_path, index=False)

            start_str = str(pd.to_datetime(df["Date"].min()).date()) if not df.empty else ""
            end_str   = str(pd.to_datetime(df["Date"].max()).date()) if not df.empty else ""
            rows.append({
                "Ticker": t,
                "Rows": len(df),
                "Start": start_str,
                "End": end_str,
                "FirstClose": df["Close"].iloc[0] if "Close" in df and len(df) else "",
                "LastClose": df["Close"].iloc[-1] if "Close" in df and len(df) else "",
            })

            print(f"[DONE] {t}: {len(df)} rows saved to {csv_path}")

        except Exception as e:
            print(f"[ERROR] Skipping {t}: {e}")
            rows.append({"Ticker": t, "Rows": 0, "Start": "", "End": "", "FirstClose": "", "LastClose": ""})

    # Write summary
    summary = pd.DataFrame(rows)
    summary_csv = os.path.join(outdir, "summary.csv")
    summary.to_csv(summary_csv, index=False)
    print(f"[DONE] Summary saved to {summary_csv}")

if __name__ == "__main__":
    main()
