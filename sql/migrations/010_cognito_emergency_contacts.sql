-- Add only the two approved native emergency contact keys; legacy keys remain unchanged.
ALTER TABLE raw_cognito_waivers
    DROP CONSTRAINT ck_cognito_waivers_approved,
    ADD CONSTRAINT ck_cognito_waivers_approved CHECK (JSON_TYPE(raw_values) = 'OBJECT' AND (JSON_CONTAINS('["Email","Date Submitted","Name","Phone","Text Friendly","Student Birthdate","Address","Print Name","Date","Relationship to student"]', JSON_KEYS(raw_values)) = 1 OR JSON_CONTAINS('["BloomsburgMartialArtsAcademy2_Id","StudentName_First","StudentName_Last","Phone","TextFriendly","StudentBirthdate","Email","Address_Line1","Address_Line2","Address_City","Address_State","Address_PostalCode","PrintName","Date","RelationshipToStudent","Entry_DateSubmitted","EmergencyContact","Phone2"]', JSON_KEYS(raw_values)) = 1));

-- Nullable additions preserve existing normalized and historical raw records.
ALTER TABLE cognito_waivers
    ADD emergency_contact_name VARCHAR(255) NULL,
    ADD emergency_contact_phone VARCHAR(100) NULL;
