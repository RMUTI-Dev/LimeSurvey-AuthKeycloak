# AuthKeycloak — ปลั๊กอิน Keycloak OIDC Single Sign-On สำหรับ LimeSurvey

*[Read in English](README.md) — ฉบับภาษาอังกฤษคือ source of truth ฉบับนี้เป็นคำแปล
อาจอัปเดตตามหลังฉบับภาษาอังกฤษได้ ถ้าเนื้อหาขัดแย้งกันให้ยึดฉบับภาษาอังกฤษเป็นหลัก*

ปลั๊กอิน authentication สำหรับ LimeSurvey ที่ให้ admin login ผ่าน Keycloak
identity provider (OpenID Connect) แทนที่ (หรือควบคู่กับ) username/password
เดิมของ LimeSurvey เอง

พัฒนาขึ้นครั้งแรกสำหรับระบบ Keycloak ของ มทร.อีสาน (`passport.rmuti.ac.th`)
แล้ว generalize ให้สถาบันอื่นที่ใช้ Keycloak นำไปใช้ได้เช่นกัน

## หลักการทำงาน

1. Admin กด "เข้าสู่ระบบด้วย SSO" (หรือตั้งให้ปลั๊กอินนี้เป็นวิธี login เริ่มต้น)
2. ปลั๊กอิน redirect ไปยัง authorization endpoint ของ Keycloak realm ที่ตั้งค่าไว้
3. Keycloak redirect กลับมาพร้อม authorization code
4. ปลั๊กอินแลก code เป็น token, ดึงข้อมูลผู้ใช้ผ่าน `/userinfo`, แล้วหาหรือสร้าง
   LimeSurvey user ที่ตรงกันโดยอัตโนมัติ
5. สร้าง LimeSurvey session ให้ แล้วพา admin เข้าหน้า admin home

## ความต้องการของระบบ

- LimeSurvey 6.0 หรือ 7.0 (ดู `<compatibility>` ใน `config.xml`)
- Keycloak realm และ client ที่ตั้งค่าไว้สำหรับ LimeSurvey instance นี้แล้ว
  (confidential client, เปิด standard flow)

## วิธีติดตั้ง

1. โหลด zip ปลั๊กอินจากหน้า [Releases](../../releases) (โครงสร้างไฟล์ถูกต้อง
   อยู่แล้ว มีโฟลเดอร์บนสุดชื่อ `AuthKeycloak/` พอดี ไม่ต้อง rename อะไรหลัง unzip)
2. ใน LimeSurvey: **Configuration → Plugins → Upload & install** → เลือกไฟล์ zip
3. Activate ปลั๊กอิน แล้วเข้าไปตั้งค่า Keycloak ต่อ (ดูหัวข้อถัดไป)

## การตั้งค่า

ตั้งค่าได้จาก **Configuration → Plugins → AuthKeycloak → Settings** หรือผ่าน
environment variable (env var จะมาก่อนเสมอ — เหมาะกับ deployment แบบ K8s/Docker
ที่จัดการ setting ผ่าน DB ไม่สะดวก):

| ตั้งค่า                      | Env var                    | ค่าเริ่มต้น                     | หมายเหตุ |
|-----------------------------|-----------------------------|---------------------------------|-------|
| Keycloak Base URL           | `KEYCLOAK_URL`              | *(ไม่มี ต้องตั้งเอง)*            | เช่น `https://sso.example.org` |
| Realm                       | `KEYCLOAK_REALM`             | *(ไม่มี ต้องตั้งเอง)*            | |
| Client ID                   | `KEYCLOAK_CLIENT_ID`         | *(ไม่มี ต้องตั้งเอง)*            | |
| Client Secret               | `KEYCLOAK_CLIENT_SECRET`     | *(ไม่มี ต้องตั้งเอง)*            | **ห้าม commit ค่านี้เด็ดขาด** — ตั้งผ่าน admin panel หรือ env var/secret store เท่านั้น |
| Verify SSL certificate      | `KEYCLOAK_VERIFY_SSL`        | **เปิด**                        | ปิดเฉพาะกรณี Keycloak endpoint ใช้ self-signed หรือ internal CA cert เท่านั้น — แนะนำให้เปิดไว้เสมอ |
| ตั้งเป็นวิธี login เริ่มต้น    | —                            | ปิด                              | |
| สร้าง user อัตโนมัติเมื่อ login ครั้งแรก | —                | เปิด                             | |
| ให้สิทธิ์สร้าง survey กับ user ที่สร้างอัตโนมัติ | —         | ปิด                              | |
| อนุญาต admin เริ่มต้น (uid=1) ใช้ SSO ได้ | —               | ปิด                              | ปิดไว้ช่วยให้ uid=1 ยังมีรหัสผ่าน local สำรองไว้ใช้ได้เสมอ แม้ SSO จะมีปัญหา |

## หมายเหตุด้านความปลอดภัย

- Client secret อ่านจาก LimeSurvey plugin settings storage หรือ environment
  variable เท่านั้น — ไม่มี hardcode ไว้ในโค้ดของ repo นี้เลย
- SSL verification ตั้งเป็น **เปิด** โดย default ปิดได้เฉพาะกรณีที่เข้าใจความเสี่ยง
  จริง (การปิดจะเอาการป้องกัน man-in-the-middle ระหว่าง LimeSurvey กับ Keycloak
  server ออกไป)
- Error detail จาก Keycloak (เช่น token exchange ล้มเหลว) จะ log ไว้ฝั่ง server
  เท่านั้น ไม่โชว์ให้ browser เห็น เพราะ callback endpoint นี้เข้าถึงได้ตั้งแต่
  ก่อน login เสร็จ ไม่ควรให้ผู้ใช้ที่ยังไม่ login เห็นรายละเอียดภายในของ IdP

## License

GNU General Public License v2.0 or later — ดู [LICENSE](LICENSE) license
เดียวกับ LimeSurvey เอง เพราะปลั๊กอินนี้เขียนอิงกับ `AuthPluginBase` ของ
LimeSurvey core โดยตรง

## เครดิต

พัฒนาครั้งแรกโดย RMUTI OARIT (สำนักวิทยบริการและเทคโนโลยีสารสนเทศ
มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน)
