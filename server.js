// server.js - WebSocket Server for Real-time Communication
const express = require('express');
const http = require('http');
const socketIo = require('socket.io');
const mysql = require('mysql2/promise');

const app = express();
const server = http.createServer(app);
const io = socketIo(server, {
    cors: {
        origin: "http://localhost",
        methods: ["GET", "POST"]
    }
});

// Database configuration
const dbConfig = {
    host: 'localhost',
    user: 'root',
    password: '',
    database: 'plmun_emotion_ai'
};

// Store active connections
const activeRooms = new Map();

io.on('connection', (socket) => {
    console.log('New client connected:', socket.id);
    
    // Join classroom
    socket.on('join-classroom', async (data) => {
        const { roomId, userId, userRole, userName } = data;
        
        // Join room
        socket.join(roomId);
        
        // Store connection info
        socket.roomId = roomId;
        socket.userId = userId;
        socket.userRole = userRole;
        socket.userName = userName;
        
        // Add to active rooms
        if (!activeRooms.has(roomId)) {
            activeRooms.set(roomId, new Map());
        }
        
        activeRooms.get(roomId).set(userId, {
            socketId: socket.id,
            userName: userName,
            userRole: userRole,
            joinedAt: new Date()
        });
        
        // Notify others in the room
        if (userRole === 'student') {
            socket.to(roomId).emit('student-joined', {
                userId: userId,
                userName: userName
            });
        }
        
        // Send current participants to the new user
        const participants = Array.from(activeRooms.get(roomId).values())
            .filter(p => p.userId !== userId);
        
        socket.emit('current-participants', participants);
        
        console.log(`${userName} (${userRole}) joined room ${roomId}`);
    });
    
    // Handle WebRTC signaling
    socket.on('offer', (data) => {
        socket.to(data.to).emit('offer', {
            from: socket.userId,
            offer: data.offer
        });
    });
    
    socket.on('answer', (data) => {
        socket.to(data.to).emit('answer', {
            from: socket.userId,
            answer: data.answer
        });
    });
    
    socket.on('ice-candidate', (data) => {
        socket.to(data.to).emit('ice-candidate', {
            from: socket.userId,
            candidate: data.candidate
        });
    });
    
    // Handle emotion updates from students
    socket.on('emotion-update', async (data) => {
        const { sessionId, emotion, confidence, engagement } = data;
        
        // Broadcast to teacher
        socket.to(socket.roomId).emit('emotion-update', {
            studentId: socket.userId,
            studentName: socket.userName,
            emotion: emotion,
            confidence: confidence,
            engagement: engagement
        });
        
        // Save to database
        try {
            const connection = await mysql.createConnection(dbConfig);
            await connection.execute(
                'INSERT INTO emotion_data (session_id, student_id, facial_emotion, confidence_score, engagement_level) VALUES (?, ?, ?, ?, ?)',
                [sessionId, socket.userId, emotion, confidence, engagement]
            );
            await connection.end();
        } catch (error) {
            console.error('Database error:', error);
        }
    });
    
    // Handle attendance updates
    socket.on('attendance-update', (data) => {
        socket.to(socket.roomId).emit('attendance-update', data);
    });
    
    // Handle engagement updates
    socket.on('engagement-update', (data) => {
        socket.to(socket.roomId).emit('engagement-update', data);
    });
    
    // Handle chat messages
    socket.on('chat-message', (data) => {
        const message = {
            from: socket.userId,
            userName: socket.userName,
            userRole: socket.userRole,
            message: data.message,
            timestamp: new Date()
        };
        
        // Broadcast to room
        io.to(socket.roomId).emit('chat-message', message);
        
        // Save to database
        saveChatMessage(data.sessionId, message);
    });
    
    // Handle session control
    socket.on('end-session', (data) => {
        // Only instructor can end session
        if (socket.userRole === 'instructor') {
            io.to(socket.roomId).emit('session-ended');
            
            // Clean up room
            activeRooms.delete(socket.roomId);
        }
    });
    
    // Handle disconnection
    socket.on('disconnect', () => {
        console.log('Client disconnected:', socket.id);
        
        if (socket.roomId && socket.userId) {
            const room = activeRooms.get(socket.roomId);
            if (room) {
                room.delete(socket.userId);
                
                // Notify others if student left
                if (socket.userRole === 'student') {
                    socket.to(socket.roomId).emit('student-left', {
                        userId: socket.userId,
                        userName: socket.userName
                    });
                }
                
                // Remove room if empty
                if (room.size === 0) {
                    activeRooms.delete(socket.roomId);
                }
            }
        }
    });
    
    // Leave classroom
    socket.on('leave-classroom', (data) => {
        const { roomId, userId } = data;
        
        if (activeRooms.has(roomId)) {
            activeRooms.get(roomId).delete(userId);
            
            // Notify others if student left
            if (socket.userRole === 'student') {
                socket.to(roomId).emit('student-left', {
                    userId: userId,
                    userName: socket.userName
                });
            }
        }
        
        socket.leave(roomId);
    });
});

// Save chat message to database
async function saveChatMessage(sessionId, message) {
    try {
        const connection = await mysql.createConnection(dbConfig);
        await connection.execute(
            'INSERT INTO chat_messages (session_id, sender_id, sender_role, message) VALUES (?, ?, ?, ?)',
            [sessionId, message.from, message.userRole, message.message]
        );
        await connection.end();
    } catch (error) {
        console.error('Error saving chat message:', error);
    }
}

const PORT = process.env.PORT || 3000;
server.listen(PORT, () => {
    console.log(`WebSocket server running on port ${PORT}`);
});