-- Preserve legacy source keys and explicitly approve only the BMA2 native export keys.
ALTER TABLE raw_cognito_waivers
    DROP CONSTRAINT ck_cognito_waivers_approved,
    ADD CONSTRAINT ck_cognito_waivers_approved CHECK (JSON_TYPE(raw_values) = 'OBJECT' AND (JSON_CONTAINS('["Email","Date Submitted","Name","Phone","Text Friendly","Student Birthdate","Address","Print Name","Date","Relationship to student"]', JSON_KEYS(raw_values)) = 1 OR JSON_CONTAINS('["BloomsburgMartialArtsAcademy2_Id","StudentName_First","StudentName_Last","Phone","TextFriendly","StudentBirthdate","Email","Address_Line1","Address_Line2","Address_City","Address_State","Address_PostalCode","PrintName","Date","RelationshipToStudent","Entry_DateSubmitted"]', JSON_KEYS(raw_values)) = 1));

-- New source-label metadata never creates a BMA person, member or relationship.
ALTER TABLE cognito_waivers
    ADD source_format VARCHAR(30) NOT NULL DEFAULT 'legacy',
    ADD source_name_role VARCHAR(20) NOT NULL DEFAULT 'unresolved',
    ADD source_student_first_name VARCHAR(255) NULL,
    ADD source_student_last_name VARCHAR(255) NULL,
    ADD CONSTRAINT ck_waiver_source_labels CHECK ((source_format = 'legacy' AND source_name_role = 'unresolved') OR (source_format = 'bma2_v1' AND source_name_role = 'student'));
