#!/usr/bin/env python3
import sys, json

def main():
    try:
        payload = json.loads(sys.stdin.read() or "{}")
        name = payload.get("name", "world")
        result = {"message": f"Hello, {name}!", "ok": True}
        print(json.dumps(result))
    except Exception as e:
        print(json.dumps({"ok": False, "error": str(e)}))
        sys.exit(1)

if __name__ == "__main__":
    main()
