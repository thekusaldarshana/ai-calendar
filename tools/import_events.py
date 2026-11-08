#!/usr/bin/env python3
"""Bulk-import events (Google Calendar event JSON) into a Google Calendar via a service account.

Idempotent: each event must carry extendedProperties.private[<key-property>] = a unique id;
an event is skipped if the calendar already has one with that id.

Usage: import_events.py CALENDAR_ID --events events.json --key service-account.json
                        [--key-property import_key] [--skip id1,id2] [--dry-run]
Run with `python3 -I` so nothing in the current directory is imported.
"""
import argparse, base64, json, os, subprocess, tempfile, time, urllib.error, urllib.parse, urllib.request

API = 'https://www.googleapis.com/calendar/v3'

def b64(x): return base64.urlsafe_b64encode(x).rstrip(b'=')

def token(acct):
    now = int(time.time())
    unsigned = b64(json.dumps({'alg': 'RS256', 'typ': 'JWT'}).encode()) + b'.' + b64(json.dumps({
        'iss': acct['client_email'], 'scope': 'https://www.googleapis.com/auth/calendar',
        'aud': acct['token_uri'], 'iat': now, 'exp': now + 3600}).encode())
    with tempfile.NamedTemporaryFile('w', delete=False) as t:
        os.chmod(t.name, 0o600); t.write(acct['private_key'])
    try:
        sig = subprocess.run(['openssl', 'dgst', '-sha256', '-sign', t.name], input=unsigned, capture_output=True, check=True).stdout
    finally:
        os.unlink(t.name)
    body = urllib.parse.urlencode({'grant_type': 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                                   'assertion': (unsigned + b'.' + b64(sig)).decode()}).encode()
    return json.load(urllib.request.urlopen(urllib.request.Request('https://oauth2.googleapis.com/token', body), timeout=30))['access_token']

def call(tok, method, path, body=None):
    req = urllib.request.Request(API + path, method=method, headers={'Authorization': 'Bearer ' + tok, 'Content-Type': 'application/json'},
                                 data=json.dumps(body).encode() if body is not None else None)
    try:
        return json.load(urllib.request.urlopen(req, timeout=30))
    except urllib.error.HTTPError as e:
        raise SystemExit(f'Google API error {e.code}: {json.load(e)["error"]["message"]}')

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('calendar_id'); ap.add_argument('--events', required=True); ap.add_argument('--key', required=True)
    ap.add_argument('--key-property', default='import_key'); ap.add_argument('--skip', default=''); ap.add_argument('--dry-run', action='store_true')
    a = ap.parse_args()
    skip = {s for s in a.skip.split(',') if s}
    tok = token(json.load(open(os.path.expanduser(a.key))))
    cal = urllib.parse.quote(a.calendar_id, safe='')
    for ev in json.load(open(a.events)):
        key = ev['extendedProperties']['private'][a.key_property]
        if key in skip:
            print(f'skip (requested)  {key}'); continue
        found = call(tok, 'GET', f'/calendars/{cal}/events?maxResults=1&privateExtendedProperty=' + urllib.parse.quote(a.key_property + '=' + key))
        if found.get('items'):
            print(f'exists            {key}'); continue
        if a.dry_run:
            print(f'would create      {key}'); continue
        r = call(tok, 'POST', f'/calendars/{cal}/events', ev)
        print(f'created           {key}  ->  {r["id"]}')

if __name__ == '__main__':
    main()
