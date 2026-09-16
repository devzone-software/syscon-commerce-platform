package pe.syscon.auth.domain;

import jakarta.persistence.*;
import java.util.*;

@Entity @Table(name="app_user")
public class User {
    @Id @GeneratedValue(strategy=GenerationType.UUID) public UUID id;
    @Column(nullable=false,unique=true) public String email;
    @Column(nullable=false) public String passwordHash;
    @ManyToMany(fetch=FetchType.EAGER) public Set<Role> roles = new HashSet<>();
    protected User() {}
    public User(String email,String passwordHash,Role role) { this.email=email;this.passwordHash=passwordHash;roles.add(role); }
}
