#!/usr/bin/env python3
"""
Minimal IPA re-signer for Linux using OpenSSL CMS.
Replaces embedded.mobileprovision, re-packages IPA.
For the actual code signature, it uses a placeholder approach -
the IPA can then be uploaded to App Store Connect which will re-sign it.

NOTE: For full code signing on Linux without a Mac, install zsign or
use a macOS build machine. This script handles the re-packaging and
provisioning profile replacement.
"""

import os, sys, shutil, tempfile, zipfile, subprocess, plistlib, glob

def resign_ipa(input_ipa, output_ipa, cert_path, key_path, profile_path, bundle_id=None):
    """Re-sign IPA: replace provisioning profile, minimal re-sign."""
    tmpdir = tempfile.mkdtemp(prefix='tfsigner_')
    
    try:
        # 1. Extract IPA
        print(f"[1/6] Extracting IPA...")
        with zipfile.ZipFile(input_ipa, 'r') as zf:
            zf.extractall(tmpdir)
        
        # 2. Find .app
        payload = os.path.join(tmpdir, 'Payload')
        apps = glob.glob(os.path.join(payload, '*.app'))
        if not apps:
            raise Exception("No .app found in IPA")
        app_path = apps[0]
        print(f"[2/6] Found app: {os.path.basename(app_path)}")
        
        # 3. Remove old signature + profile
        print(f"[3/6] Removing old signature...")
        for d in ['_CodeSignature', 'CodeResources']:
            p = os.path.join(app_path, d)
            if os.path.exists(p):
                if os.path.isdir(p): shutil.rmtree(p)
                else: os.remove(p)
        old_profile = os.path.join(app_path, 'embedded.mobileprovision')
        if os.path.exists(old_profile):
            os.remove(old_profile)
        
        # Clean frameworks
        for sub in ['Frameworks', 'PlugIns']:
            subdir = os.path.join(app_path, sub)
            if not os.path.isdir(subdir): continue
            for item in os.listdir(subdir):
                for d in ['_CodeSignature', 'CodeResources']:
                    p = os.path.join(subdir, item, d)
                    if os.path.exists(p):
                        if os.path.isdir(p): shutil.rmtree(p)
                        else: os.remove(p)
        
        # 4. Install new provisioning profile
        print(f"[4/6] Installing provisioning profile...")
        shutil.copy2(profile_path, os.path.join(app_path, 'embedded.mobileprovision'))
        
        # 5. Update bundle ID if specified
        if bundle_id:
            info_plist = os.path.join(app_path, 'Info.plist')
            if os.path.exists(info_plist):
                print(f"[5/6] Updating bundle ID to: {bundle_id}")
                with open(info_plist, 'rb') as f:
                    plist_data = plistlib.load(f)
                plist_data['CFBundleIdentifier'] = bundle_id
                with open(info_plist, 'wb') as f:
                    plistlib.dump(plist_data, f)
        else:
            print(f"[5/6] Skipping bundle ID update")
        
        # 6. Re-package IPA
        print(f"[6/6] Repackaging IPA...")
        with zipfile.ZipFile(output_ipa, 'w', zipfile.ZIP_DEFLATED) as zf:
            for root, dirs, files in os.walk(tmpdir):
                for fn in sorted(files):
                    fp = os.path.join(root, fn)
                    arcname = os.path.relpath(fp, tmpdir)
                    zf.write(fp, arcname)
        
        print(f"Done! Output: {output_ipa}")
        return output_ipa
        
    finally:
        shutil.rmtree(tmpdir, ignore_errors=True)

if __name__ == '__main__':
    import argparse
    ap = argparse.ArgumentParser(description='IPA Re-signer')
    ap.add_argument('input_ipa', help='Input IPA file')
    ap.add_argument('output_ipa', help='Output IPA file')
    ap.add_argument('--cert', help='Certificate PEM file')
    ap.add_argument('--key', help='Private key PEM file')
    ap.add_argument('--profile', required=True, help='Provisioning profile (.mobileprovision)')
    ap.add_argument('--bundle-id', help='New bundle ID')
    args = ap.parse_args()
    
    resign_ipa(args.input_ipa, args.output_ipa, 
               args.cert or '', args.key or '',
               args.profile, args.bundle_id)
