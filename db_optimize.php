-- db_optimize.sql
-- Run this once to optimize your database

-- Add missing indexes for performance
CREATE INDEX idx_classes_instructor_id ON classes(instructor_id);
CREATE INDEX idx_live_sessions_class_id ON live_sessions(class_id);
CREATE INDEX idx_live_sessions_start_time ON live_sessions(start_time);
CREATE INDEX idx_emotion_data_session_id ON emotion_data(session_id);
CREATE INDEX idx_session_attendance_session_id ON session_attendance(session_id);
CREATE INDEX idx_session_engagement_summary_session_id ON session_engagement_summary(session_id);

-- Add foreign key constraints if missing
ALTER TABLE live_session_participants 
ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE chat_messages 
ADD FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE;

-- Optimize tables
OPTIMIZE TABLE users, students, classes, class_enrollments, live_sessions;