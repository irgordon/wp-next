#!/usr/bin/env python3
"""Real publishing workflows and request boundaries on native PHP 8.4+."""
from pathlib import Path
from html.parser import HTMLParser
import base64
import hashlib
import http.cookiejar
import json
import os
import re
import shutil
import socket
import sqlite3
import subprocess
import sys
import tempfile
import tarfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parent.parent
PHP = sys.argv[1] if len(sys.argv) > 1 else shutil.which('php')
COUNT = 0
class FormParser(HTMLParser):
    def __init__(self, text):
        super().__init__(); self.forms=[]; self.current=None; self.feed(text)
    def handle_starttag(self, tag, attrs):
        attrs=dict(attrs)
        if tag=='form': self.current={'attrs':attrs,'fields':{}}; self.forms.append(self.current)
        if tag=='input' and self.current is not None and 'name' in attrs: self.current['fields'][attrs['name']]=attrs.get('value','')
    def handle_endtag(self, tag):
        if tag=='form':self.current=None

def token(body, form_id=None, action_field=None):
    for form in FormParser(body).forms:
        if form_id and form['attrs'].get('id')!=form_id:continue
        if action_field and form['fields'].get('action')!=action_field:continue
        if not form_id and not action_field and form['fields'].get('action')=='logout':continue
        if '_wpnonce' in form['fields']:return form['fields']['_wpnonce']
    raise AssertionError('No matching CSRF form found')

def check(ok, name):
    global COUNT
    if not ok:raise AssertionError(name)
    COUNT+=1; print('PASS:',name,flush=True)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):return None
class Client:
    def __init__(self,base):
        self.base=base;self.jar=http.cookiejar.CookieJar();self.open=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(self.jar),NoRedirect())
    def request(self,path,data=None,headers=None):
        if isinstance(data,dict):data=urllib.parse.urlencode(data,doseq=True).encode()
        req=urllib.request.Request(self.base+path,data=data,headers=headers or {})
        try:r=self.open.open(req,timeout=10)
        except urllib.error.HTTPError as e:r=e
        return r.status,r.read().decode('utf8','replace'),dict(r.headers)
    def login(self,name,password='disposable-test-password'):
        _,body,_=self.request('/wp-admin/login.php')
        return self.request('/wp-admin/login.php',{'log':name,'pwd':password,'_wpnonce':token(body)})
    def cookie(self):return next((c.value for c in self.jar if c.name=='wp_session_token'),'')

def cli(site,env,*args):
    p=subprocess.run([PHP,str(site/'bin/wp'),*args],env=env,cwd=site,capture_output=True,text=True,timeout=30)
    if p.returncode:raise AssertionError(p.stdout+p.stderr)
    return p.stdout

def dbrow(path,sql,params=()):
    with sqlite3.connect(path) as db:
        db.row_factory=sqlite3.Row;r=db.execute(sql,params).fetchone();return dict(r) if r else None

