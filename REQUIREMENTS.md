# Requirements — AuthKeycloak (LimeSurvey Keycloak OIDC SSO plugin)

> **หมายเหตุสำคัญ**: ไฟล์นี้มี 2 ชุดที่ต้อง sync กันเสมอ:
> - `application/core/plugins/AuthKeycloak/REQUIREMENTS.md` ใน monorepo `rmuti-limesurvey` (source of truth ที่ deploy จริง)
> - `REQUIREMENTS.md` ใน public repo [`RMUTI-Dev/LimeSurvey-AuthKeycloak`](https://github.com/RMUTI-Dev/LimeSurvey-AuthKeycloak)
>
> **ทุกครั้งที่แก้ `AuthKeycloak.php`/`config.xml` หรือ requirement นี้ในฝั่ง monorepo ต้อง copy ไฟล์ที่เปลี่ยน + bump version ใน `config.xml` + push ไปที่ public repo (commit + tag release ใหม่) ด้วยเสมอ** ไม่งั้นโค้ด public จะเก่ากว่าที่ deploy จริงเงียบๆ

## 1. ภาพรวม

Plugin สำหรับ LimeSurvey ที่ให้ admin login ผ่าน Keycloak (OpenID Connect) แทน/ควบคู่กับ username-password เดิมของ LimeSurvey เอง เขียนขึ้นแทนการใช้ `mod_auth_openidc` เพราะทีมงานไม่มีสิทธิ์เข้าถึง K8s Secret ของ Infra team — config ทั้งหมดเก็บผ่าน LimeSurvey plugin settings (DB) หรือ env var แทน

## 2. Functional Requirements

### FR-01 — OIDC Authorization Code flow
ผู้ใช้กด "เข้าสู่ระบบด้วย RMUTI SSO" (หรือเลือก "RMUTI Passport" จาก dropdown auth plugin) → redirect ไป Keycloak authorize endpoint → Keycloak redirect กลับพร้อม `code`+`state` → plugin แลก code เป็น token → ดึง userinfo → login เข้า LimeSurvey session

### FR-02 — CSRF protection ด้วย state parameter
สุ่ม `state` เก็บใน session ก่อน redirect, เช็คตรงตอน callback, หมดอายุใน 10 นาที (`time() - stateTime > 600`)

### FR-03 — Auto-create user ครั้งแรกที่ login
ถ้าไม่มี LimeSurvey user ตรงกับ `preferred_username`/`sub` จาก Keycloak และเปิด `autocreate` (default เปิด) จะสร้าง user ใหม่อัตโนมัติ พร้อม permission `auth_keycloak` และ (ถ้าเปิด `automaticsurveycreation`) สิทธิ์สร้าง survey

### FR-04 — Sync ชื่อ/อีเมลทุกครั้งที่ login
User ที่มีอยู่แล้วจะถูกอัปเดต `full_name`/`email` จาก Keycloak ทุกครั้ง (รองรับ `firstNameThai`/`lastNameThai` ของ RMUTI LDAP ก่อน แล้ว fallback ไป `given_name`/`family_name`/`name`)

### FR-05 — ป้องกัน uid=1 ใช้ SSO โดย default
บัญชี admin เริ่มต้น (uid=1) login ผ่าน SSO ไม่ได้ ยกเว้นเปิด `allow_initial_user` — กันไม่ให้ SSO พังแล้วเข้า LimeSurvey ไม่ได้เลยสักทาง

### FR-06 — ซ่อนปุ่ม Change Password/Email สำหรับ SSO user
เพราะบัญชีถูกจัดการที่ Keycloak/LDAP ไม่ใช่ใน LimeSurvey เอง

### FR-07 — Auto-redirect เมื่อเลือก "RMUTI Passport" จาก dropdown
รองรับทั้ง native `change` event และ bootstrap-select (custom widget ของ LimeSurvey)

### FR-08 — Logout redirect ไปหน้า public
`afterLogout()` redirect ไป `PUBLIC_URL` (env var) แทนหน้า login เดิม

## 3. Non-Functional Requirements

### NFR-01 — Security
- `client_secret` ไม่ hardcode ในโค้ดเด็ดขาด อ่านจาก DB (plugin settings, เข้ารหัสโดย LimeSurvey เอง) หรือ env var เท่านั้น
- **SSL certificate verification เปิดเป็น default** (`verify_ssl` setting, default `'1'`) — ปิดได้เฉพาะกรณี Keycloak endpoint ใช้ self-signed/internal CA cert เท่านั้น
- Error detail จาก Keycloak (เช่น token exchange ล้มเหลว) log ผ่าน `error_log()` เท่านั้น **ไม่โชว์ raw detail ให้ browser เห็น** เพราะ callback endpoint เข้าถึงได้ก่อน login เสร็จ

### NFR-02 — Config precedence
Env var มาก่อนเสมอ (`KEYCLOAK_URL`, `KEYCLOAK_REALM`, `KEYCLOAK_CLIENT_ID`, `KEYCLOAK_CLIENT_SECRET`, `KEYCLOAK_VERIFY_SSL`) แล้วค่อย fallback ไป DB setting — ออกแบบมาให้ตั้งค่าผ่าน K8s ConfigMap/Secret ได้โดยไม่ต้องเข้า Admin Panel

### NFR-03 — Compatibility
LimeSurvey 6.0 และ 7.0 (ตาม `config.xml` `<compatibility>`)

## 4. Out of Scope

- **Keycloak-side Single Logout (SLO)** — `afterLogout()` แค่ redirect ฝั่ง LimeSurvey เท่านั้น ไม่ได้เรียก Keycloak `end_session_endpoint` เพื่อตัด session ฝั่ง IdP ด้วย (ผู้ใช้ logout จาก LimeSurvey แล้ว Keycloak session ยังอยู่ ถ้ากด login ใหม่จะเข้าได้ทันทีไม่ต้องใส่รหัสซ้ำ)
- Multi-realm/multi-client ต่อ 1 instance — รองรับแค่ 1 realm + 1 client ต่อการติดตั้ง 1 ครั้ง
- Non-admin (participant/respondent) login ผ่าน SSO — plugin นี้ครอบคลุมแค่ admin login

## 5. Known Limitations

- **verify_ssl เพิ่งเปลี่ยน default จาก "ปิด" เป็น "เปิด"** (แก้เมื่อเตรียม publish เป็น open source) — production ของ RMUTI เอง**ยังไม่เคยทดสอบ**กับ network path จริงในคลัสเตอร์ว่าเปิด verify แล้ว SSO ยังทำงานได้ปกติไหม (ทดสอบแยกแล้วว่า cert สาธารณะของ `passport.rmuti.ac.th` เป็น Let's Encrypt ปกติ ไม่ใช่ self-signed จึง "ควร" ผ่าน แต่ path จาก container ในคลัสเตอร์อาจต่างจากที่ทดสอบจากเครื่อง dev) — ถ้ายังไม่ได้ทดสอบ ให้ปิด `verify_ssl` ไว้ก่อนผ่านหน้า settings ของ plugin (ไม่ต้องแก้โค้ด/ไม่ต้องพึ่ง K8s)
- ไม่มี automated test (unit/integration) — verify ด้วย `php -l` + ทดสอบ curl behavior แยก (self-signed.badssl.com) ตอนแก้ verify_ssl toggle เท่านั้น ยังไม่เคยรัน end-to-end ผ่าน browser จริงจาก session ที่แก้ไขนี้
