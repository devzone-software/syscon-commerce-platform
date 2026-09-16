package pe.syscon.auth.domain;

import jakarta.persistence.*;
import java.time.Instant;
import java.util.UUID;

@Entity
public class RefreshToken {
    @Id @GeneratedValue(strategy=GenerationType.UUID) public UUID id;
    @Column(nullable=false,unique=true) public String tokenHash;
    @ManyToOne(optional=false) public User user;
    @Column(nullable=false) public Instant expiresAt;
    public boolean revoked;
    @Version public long version;
    protected RefreshToken() {}
    public RefreshToken(String hash,User user,Instant expiry) { tokenHash=hash;this.user=user;expiresAt=expiry; }
}
