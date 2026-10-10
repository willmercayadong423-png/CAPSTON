# HEHMHS — Database Normalization (UNF → 1NF → 2NF → 3NF)

> Companion to `ERD-redesign-proposal.md`. The final 3NF result of this
> walkthrough **is the live `hehmhs` database** — every table below maps to
> a real table in the system (see §7).
>
> **The story for the panel:** before this system, the registrar kept
> manual records — one logbook per student with every document request
> written under the student's name. Normalization is the process that
> turns that logbook into the relational database we built.

---

## 0. Functional Dependencies (established first)

Before normalizing, we list what determines what (from the school's rules):

| Determinant | Determines (the dependent attributes) |
|---|---|
| `StudentID` | LastName, FirstName, Email, Contact, Password, Status, RoleID, LRN, GradeLevel, SYLastAttended |
| `RoleID` | RoleName |
| `RequestID` | StudentID, DocTypeID, Purpose, ReqGradeLevel, ReqSY, ReqStatus, DateRequested, RegistrarViewedAt, DateReleased, IDPhoto, ECertificate |
| `DocTypeID` | DocName, RequiresGradeLevel, IsActive, SortOrder |
| `AnnouncementID` | Title, Message, IsActive, AuthorUserID, CreatedAt |
| `AuditID` | UserID, ActorName, ActorRole, Action, Entity, EntityID, Details, IP, CreatedAt |
| `Email` | (unique →) StudentID / UserID |
| **Business rule** | one *active* request per (StudentID, DocTypeID) — a constraint, not an FD |

---

## 1. UNF — Unnormalized Form (the registrar's logbook)

All data lives in **one file**, with **repeating groups** (a student can
have many requests; announcements and login events also pile up in the
same notebook):

```
┌────────────────────────────────────────────────────────────────────┐
│ STUDENT REQUEST LOGBOOK                          (one page/student) │
├────────────────────────────────────────────────────────────────────┤
│ Student No: 2026-0001          Name: Willmer Cayadong              │
│ Role: Student (active)         Email: cayadongwillmer81@gmail.com  │
│ Contact: 0917…                 LRN: 136125001234                   │
│ Grade Level: Grade 12          Last SY attended: 2024-2025         │
│ Password: ••••••                                                    │
│                                                                     │
│ REQUESTS:                       ← repeating group (1 student : N)   │
│  ┌ REQ-0049  Certificate of Good Moral — College application        │
│  │   Status: Pending   Requested: 2026-10-04   Valid ID: photo.jpg │
│  └ REQ-0037  Certificate of Enrollment — Scholarship                │
│      Status: Released  Requested: 2026-09-20  Cert: cert_0037.pdf  │
│                                                                     │
│ (announcement notes and login scribbles kept on the same page)      │
└────────────────────────────────────────────────────────────────────┘
```

**Problems:** repeating groups, non-atomic cells, everything duplicated,
no keys — cannot be searched, no integrity, update anomalies everywhere.

UNF relation (notation — braces = repeating group):

```
STUDENT_LOG (StudentID, LastName, FirstName, Email, Contact, Password,
             RoleName, Status, LRN, GradeLevel, SYLastAttended, Photo,
             { RequestNo, DocName, DocRequiresGL, Purpose, ReqGradeLevel,
               ReqSY, ReqStatus, DateRequested, DateReleased,
               IDPhoto, ECertificate })
```

---

## 2. 1NF — First Normal Form

**Rules applied**
1. Every cell holds a **single atomic value** (no lists, no nested groups).
2. The repeating group is **flattened**: one row per request; student data
   repeats on each row.
3. Every row is uniquely identified — choose **RequestNo** as primary key
   (one row per request).

```
STUDENT_REQUEST (RequestNo PK, StudentID, LastName, FirstName, Email,
                 Contact, Password, RoleName, Status, LRN, GradeLevel,
                 SYLastAttended, Photo, DocName, DocRequiresGL, Purpose,
                 ReqGradeLevel, ReqSY, ReqStatus, DateRequested,
                 DateReleased, IDPhoto, ECertificate)
```

| RequestNo | StudentID | LastName | FirstName | RoleName | LRN | DocName | DocReqGL | Purpose | ReqStatus |
|---|---|---|---|---|---|---|---|---|---|
| 0037 | 2026-0001 | Cayadong | Willmer | Student | 136125… | Certificate of Enrollment | 1 | Scholarship | Released |
| 0049 | 2026-0001 | Cayadong | Willmer | Student | 136125… | Certificate of Good Moral | 0 | College application | Pending |
| 0050 | 2026-0002 | Dela Cruz | Juan | Student | 123456… | Certificate of Good Moral | 0 | Employment | Pending |

**✅ 1NF satisfied** — atomic values, unique rows, PK identified.

**Remaining problems (visible in the sample rows):**
- **Redundancy** — Willmer's name/email/LRN copied on *every* request row.
- **Update anomaly** — fixing a misspelled email means touching many rows.
- **Insert anomaly** — a new student with no requests yet has nowhere to live
  (RequestNo is the PK).
- **Deletion anomaly** — deleting a student's last request deletes the student.

---

## 3. 2NF — Second Normal Form

**Rule applied:** 1NF, **and** no *partial dependency* — no attribute may
depend on only part of a composite key.

Take the natural composite key of the flattened table:
**(StudentID, RequestNo)**. Checking every attribute:

| Attribute group | Depends on | Verdict |
|---|---|---|
| LastName, FirstName, Email, Contact, Password, RoleName, Status, LRN, GradeLevel, SY, Photo | **StudentID only** | ❌ partial dependency |
| DocRequiresGL | **DocName only** | ❌ partial dependency |
| Purpose, ReqGradeLevel, ReqSY, ReqStatus, dates, files | **the whole key** | ✅ |

Split into three relations — each non-key attribute now depends on the
whole key of its relation:

```
STUDENT (StudentID PK, LastName, FirstName, Email, Contact, Password,
         RoleName, Status, LRN, GradeLevel, SYLastAttended, Photo)

DOCUMENT_TYPE (DocName PK, RequiresGradeLevel)

REQUEST (StudentID, RequestNo PK, DocName, Purpose, ReqGradeLevel, ReqSY,
         ReqStatus, DateRequested, DateReleased, IDPhoto, ECertificate)
         FK: StudentID → STUDENT, DocName → DOCUMENT_TYPE
```

**✅ 2NF satisfied** — anomalies from the sample table are gone:
a student exists with zero requests, one request per row, type rules
stored once.

---

## 4. 3NF — Third Normal Form

**Rule applied:** 2NF, **and** no *transitive dependency* — non-key
attributes may not depend on other non-key attributes.

**Found transitive / lookup dependencies:**

| Where | Chain | Fix |
|---|---|---|
| STUDENT | StudentID → RoleName, and RoleName is free text (typos possible: "Studnet") with no integrity | Extract **ROLES(RoleID PK, RoleName)**; STUDENT stores only `RoleID` (FK) |
| REQUEST | RequestNo → DocName → RequiresGradeLevel (type details ride along as text) | Give **DOCUMENT_TYPE a surrogate key DocTypeID**; REQUEST stores `DocTypeID` (FK). Type details live once |
| ANNOUNCEMENT | AnnouncementID → AuthorEmail → AuthorName, Role | Author becomes `CreatedBy` (FK → USERS) |
| REQUEST | RequestNo → (registrar handling it) → officer name on the certificate | Officer name is **captured on the certificate itself**, not stored as request data |

```
ROLES (RoleID PK, RoleName)                    1=Student 2=Registrar 3=Admin

STUDENT (StudentID PK, RoleID FK→ROLES, LastName, FirstName, Email,
         Contact, Password, Status, LRN, GradeLevel, SYLastAttended, Photo)

DOCUMENT_TYPE (DocTypeID PK, DocName, RequiresGradeLevel, IsActive, SortOrder)

REQUEST (RequestID PK, StudentID FK, DocTypeID FK, Purpose, ReqGradeLevel,
         ReqSY, ReqStatus, DateRequested, DateReleased, IDPhoto, ECertificate)
```

**✅ 3NF satisfied** — every non-key attribute depends on *the key, the
whole key, and nothing but the key*.

---

## 5. Beyond 3NF — the refinements the implemented DB adds

These are not required by 3NF, but they make the design cleaner (good
talking points when panelists ask "what about X?"):

1. **`STUDENT_PROFILES` (1:1 split).** LRN / GradeLevel / SY apply *only*
   to students — for Registrar/Admin accounts they would always be NULL.
   Splitting them into `student_profiles` removes the NULL columns and
   models the optional "is-a" relationship explicitly.
2. **Audit snapshots** (`actor_name`, `actor_role` in `audit_logs`) are
   *intentionally* denormalized: an append-only trail must show the name
   **as it was at event time**, even if the account is later renamed or
   deleted. (Panelists reward knowing when NOT to normalize.)
3. **Request snapshots** (`grade_level`, `school_year_last_attended`
   inside `document_requests`) — same reasoning: the values *at request
   time*, not the student's current profile.
4. **`credential_resend_log`** is deliberately a soft (email-keyed)
   reference — it is a disposable rate-limit log, not business data.

---

## 6. Normalization at a glance

```
UNF  registrar logbook: 1 file, repeating groups, no keys
 │   flatten + atomic cells + PK
1NF  STUDENT_REQUEST: 1 row per request (student data repeated)
 │   remove PARTIAL dependencies on (StudentID, RequestNo)
2NF  STUDENT │ DOCUMENT_TYPE │ REQUEST
 │   remove TRANSITIVE dependencies (role name, type details)
3NF  ROLES │ STUDENT │ DOCUMENT_TYPE │ REQUEST (+ANNOUNCEMENT, AUDIT)
 │   1:1 profile split, intentional snapshots
     ▶ final schema: users · roles · student_profiles · document_types
       document_requests · announcements · audit_logs
       credential_resend_log · site_settings
```

## 7. 3NF result ↔ live database mapping

| Normalized relation | Implemented table |
|---|---|
| ROLES | `roles` |
| STUDENT (identity part) | `users` |
| STUDENT (academic part) | `student_profiles` |
| DOCUMENT_TYPE | `document_types` |
| REQUEST | `document_requests` |
| ANNOUNCEMENT | `announcements` |
| AUDIT | `audit_logs` |
| — (rate-limit log) | `credential_resend_log` |
| — (system config) | `site_settings` |

**Every table in the live database satisfies 1NF, 2NF and 3NF** — the
schema is the end product of this walkthrough, and the ERD
(`database/erd.html`) is its diagram.
